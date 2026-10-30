<?php

namespace Templite\Cms\Services\Cdnjs;

use Illuminate\Http\UploadedFile;
use Templite\Cms\Models\Library;
use Templite\Cms\Services\LibraryFileSync;
use Templite\Cms\Services\Media\RemoteFileFetcher;

/**
 * Единственное место, где файлы пакета cdnjs попадают на диск.
 *
 * Здесь же живут потолки, бюджет времени и сверка контрольной суммы:
 * вызывающему остаётся разобрать результат и ответить в своей форме.
 */
class CatalogDownloader
{
    /**
     * Потолок на файл библиотеки, общий для ручной загрузки и для
     * скачивания из каталога: иначе через каталог проходило бы в десять
     * раз больше, чем руками.
     */
    public const MAX_FILE_KB = 5120;

    /**
     * Потолок на число файлов за один вызов. Значение одно на оба пути:
     * контроллер отсекает лишнее правилом валидации, здесь тот же предел
     * держится для ассистента, чей список правилами не проверяется.
     *
     * Только для скачивания: подключение ссылками ничего не грузит и не
     * пишет на диск, поэтому там лимит не нужен.
     */
    public const MAX_FILES = 20;

    /**
     * Каждый файл может ждать таймаут RemoteFileFetcher, поэтому у всего
     * вызова свой потолок: иначе двадцать недоступных файлов дадут пять
     * минут и обрыв по таймауту веб-сервера вместо внятного ответа.
     *
     * Сорок, а не шестьдесят: проверка стоит перед началом итерации, поэтому
     * уже начавшаяся загрузка добавит сверху ещё до пятнадцати секунд. При
     * бюджете в шестьдесят потолок вышел бы семьдесят пять — больше, чем
     * fastcgi_read_timeout по умолчанию, и защита не сработала бы ровно
     * в том случае, ради которого добавлена.
     */
    private const CATALOG_FETCH_BUDGET = 40;

    public function __construct(
        private readonly CdnjsClient $cdnjs,
        private readonly RemoteFileFetcher $fetcher,
        private readonly LibraryFileSync $sync,
    ) {
    }

    /**
     * Скачивание файлов пакета cdnjs в библиотеку.
     *
     * Общий код для веб-эндпоинта и инструмента ассистента: раньше цикл был
     * скопирован в оба места и успел разойтись по лимиту размера, бюджету
     * времени, сверке контрольной суммы и дедупликации.
     *
     * @param  array<int, string>  $names
     * @return array{added: array<int, string>, failed: array<int, array{file: string, reason: string}>}
     *
     * @throws \Templite\Cms\Exceptions\LibraryFileWriteException отказ записи на диск
     */
    public function download(Library $library, string $package, string $version, array $names): array
    {
        $names = array_values($names);
        $failed = [];

        $catalog = $this->cdnjs->files($package, $version);

        // Вызывающий уже смотрел каталог, чтобы отличить «каталог лежит» от
        // «нет такой версии», и своё сообщение построил сам — этот вызов
        // берёт готовый ответ из кэша. Сюда мы попадаем только в гонке,
        // поэтому отказ описываем пофайлово, а не отдельной формой ответа
        if (!($catalog['ok'] ?? false)) {
            foreach ($names as $name) {
                $failed[] = ['file' => $name, 'reason' => 'Каталог cdnjs сейчас недоступен.'];
            }

            return ['added' => [], 'failed' => $failed];
        }

        // Лишнее сверх предела не молчим, а объясняем: ассистенту иначе
        // непонятно, почему часть списка пропала без следа
        foreach (array_slice($names, self::MAX_FILES) as $name) {
            $failed[] = ['file' => $name, 'reason' => 'За один раз можно скачать не больше ' . self::MAX_FILES . ' файлов.'];
        }

        $names = array_slice($names, 0, self::MAX_FILES);

        // Ссылку собирает каталог, вызывающий передаёт только имя файла.
        // Имя, которого нет в этой версии, отклоняется — подставить чужой
        // адрес через параметр невозможно
        $known = collect($catalog['files'])->keyBy('file');
        $maxBytes = self::MAX_FILE_KB * 1024;

        $library->loadMissing('files');

        // Существующие строки переносим как есть: sync получает полный список
        $rows = $library->files->map(fn ($file) => [
            'id' => $file->id,
            'type' => $file->type,
            'source' => $file->source,
            'url' => $file->url,
            'version' => $file->version,
            'loading' => $file->loading,
            'media' => $file->media,
        ])->all();

        $added = [];
        // Исходники из tempnam(): storeAs() копирует, а не перемещает,
        // поэтому убрать их обязаны мы сами
        $temps = [];

        $deadline = microtime(true) + self::CATALOG_FETCH_BUDGET;

        try {
            foreach ($names as $name) {
                if (microtime(true) > $deadline) {
                    $failed[] = ['file' => $name, 'reason' => 'Превышено время ожидания, попробуйте меньше файлов за раз.'];
                    continue;
                }

                $meta = $known->get($name);

                if (!$meta) {
                    $failed[] = ['file' => $name, 'reason' => 'Файла нет в этой версии пакета.'];
                    continue;
                }

                // Повторное скачивание дало бы вторую строку и вторую копию
                // на диске: через интерфейс это ещё заметно, а ассистент не
                // знает, что уже лежит в библиотеке, и дублировал бы всегда.
                // Проверка по $added нужна отдельно: коллекция файлов за время
                // цикла не обновляется, а список из инструмента ассистента
                // вполне может содержать одно имя дважды
                // Сравниваем по basename: в каталоге имя может содержать
                // подкаталог (js/bootstrap.min.js), а RemoteFileFetcher кладёт
                // в original_name последний сегмент URL. Без этого защита
                // работала только для пакетов с файлами в корне, а у bootstrap
                // и любого пакета с раскладкой js/ + css/ повторное скачивание
                // создавало вторую запись и вторую копию на диске
                $alreadyThere = in_array($name, $added, true)
                    || $library->files->contains(
                        fn ($file) => $file->source === 'local'
                            && $file->type === $meta['type']
                            && $file->original_name === basename($name)
                            && (string) $file->version === $version
                    );

                if ($alreadyThere) {
                    $failed[] = ['file' => $name, 'reason' => 'Уже есть в библиотеке в этой версии.'];
                    continue;
                }

                $fetched = $this->fetcher->fetch($meta['url'], $maxBytes);

                if (!($fetched['ok'] ?? false)) {
                    $failed[] = ['file' => $name, 'reason' => $fetched['message'] ?? 'Не удалось скачать.'];
                    continue;
                }

                $temps[] = $fetched['file']->getRealPath();

                if ($meta['sri'] !== null && !$this->sriMatches($fetched['file'], $meta['sri'])) {
                    $failed[] = ['file' => $name, 'reason' => 'Контрольная сумма не совпала.'];
                    continue;
                }

                $rows[] = [
                    'type' => $meta['type'],
                    'source' => 'local',
                    'version' => $version,
                    'upload' => $fetched['file'],
                ];

                $added[] = $name;
            }

            // Пустой результат до sync() не доводим: переписывать список теми
            // же строками незачем, а вызывающий по пустому added сам решит,
            // считать это отказом или частичным успехом
            if ($added) {
                $this->sync->sync($library, $rows);
            }

            return ['added' => $added, 'failed' => $failed];
        } finally {
            // storeAs() копирует, а не перемещает, поэтому исходники
            // из tempnam() нужно убрать самим — иначе каждый скачанный
            // файл остаётся в /tmp полной копией. Уборка обязана идти
            // и на успехе, и на отказе, и когда sync() бросил исключение
            foreach ($temps as $temp) {
                if (is_file($temp)) {
                    @unlink($temp);
                }
            }
        }
    }

    /**
     * Сверка контрольной суммы, если каталог её дал.
     * Формат cdnjs — «sha512-<base64>»; хэши есть не у всех файлов,
     * поэтому отсутствие проверки не считается ошибкой.
     */
    private function sriMatches(UploadedFile $file, string $sri): bool
    {
        [$algo, $expected] = array_pad(explode('-', $sri, 2), 2, '');

        if (!in_array($algo, ['sha256', 'sha384', 'sha512'], true) || $expected === '') {
            return true;
        }

        return hash_equals($expected, base64_encode(hash_file($algo, $file->getRealPath(), true)));
    }
}
