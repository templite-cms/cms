<?php

namespace Templite\Cms\Services\Ai\Tools;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Templite\Cms\Models\Library;
use Templite\Cms\Services\Ai\EditorContext;
use Templite\Cms\Services\Cdnjs\CatalogDownloader;
use Templite\Cms\Services\Cdnjs\CdnjsClient;
use Templite\Cms\Services\LibraryFileSync;

class AddCdnLibraryTool implements Tool
{
    public function __construct(
        private readonly CdnjsClient $cdnjs,
        private readonly CatalogDownloader $downloader,
        private readonly LibraryFileSync $sync,
    ) {
    }

    public function name(): string
    {
        return 'add_cdn_library';
    }

    public function definition(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'package' => ['type' => 'string', 'description' => 'Имя пакета в точности как его вернул search_cdn_libraries'],
                'version' => ['type' => 'string', 'description' => 'Версия пакета; без неё берётся последняя'],
                'files' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                    'description' => 'Имена файлов из search_cdn_libraries; без них берутся рекомендуемые',
                ],
                'mode' => [
                    'type' => 'string',
                    'enum' => ['link', 'download'],
                    'description' => 'link — подключить ссылкой на CDN (по умолчанию), download — скачать файлы на сервер',
                ],
                'name' => ['type' => 'string', 'description' => 'Название библиотеки в CMS; без него берётся имя пакета'],
                'slug' => ['type' => 'string', 'description' => 'Slug библиотеки, латиницей через дефис; без него берётся имя пакета. Пригодится, если slug уже занят'],
            ],
            'required' => ['package'],
        ];
    }

    public function execute(array $args, ?EditorContext $ctx): array
    {
        $package = trim((string) ($args['package'] ?? ''));
        $info = $this->cdnjs->package($package);

        if (!($info['ok'] ?? false)) {
            return $this->catalogFailure($info, ['error' => 'package_not_found', 'package' => $package]);
        }

        $version = trim((string) ($args['version'] ?? '')) ?: $info['latest'];
        $catalog = $this->cdnjs->files($info['name'], $version);

        if (!($catalog['ok'] ?? false)) {
            return $this->catalogFailure($catalog, ['error' => 'version_not_found', 'version' => $version]);
        }

        $wanted = array_values(array_filter((array) ($args['files'] ?? [])));

        if (!$wanted) {
            $wanted = array_column(array_filter($catalog['files'], fn ($f) => $f['recommended']), 'file');
        }

        if (!$wanted) {
            return [
                'error' => 'no_files_selected',
                'package' => $info['name'],
                'version' => $version,
                // Пусто не потому, что версия неверная: у пакетов из одних
                // картинок (simple-icons) после отбора js и css не остаётся
                // ничего. Без этого числа модель пойдёт перебирать версии
                'total_files_in_version' => $catalog['total'],
            ];
        }

        // Длину режем здесь: колонка varchar(255), и строка длиннее дала бы
        // SQLSTATE с хостом, именем базы и текстом INSERT прямо в диалог
        $name = mb_substr(trim((string) ($args['name'] ?? '')) ?: $info['name'], 0, 255);
        // 100 — граница веб-валидации: slug входит в путь файла дважды
        // (cms/libraries/{slug}/{slug}-XXXXXXXX.js), а path — varchar(255)
        $slug = mb_substr(Str::slug(trim((string) ($args['slug'] ?? '')) ?: $info['name']), 0, 100);

        // Правило то же, что у store(): иначе библиотеку нельзя будет
        // сохранить из админки — PUT отклонит её собственный slug
        if (!preg_match('/^[a-z][a-z0-9\-]*$/', $slug)) {
            return [
                'error' => 'invalid_package_name',
                'message' => "Из имени «{$info['name']}» не получается slug: он должен начинаться с латинской буквы и состоять из латиницы, цифр и дефисов. Передайте slug явно.",
            ];
        }

        if (Library::where('slug', $slug)->exists()) {
            return [
                'error' => 'slug_taken',
                'slug' => $slug,
                'message' => 'Библиотека с таким slug уже есть. Передайте другой slug или сообщите пользователю.',
            ];
        }

        $mode = ($args['mode'] ?? 'link') === 'download' ? 'download' : 'link';

        $library = Library::create([
            'name' => $name,
            'slug' => $slug,
            'sort_order' => 0,
            'active' => true,
        ]);

        try {
            $result = $mode === 'download'
                ? $this->downloader->download($library, $info['name'], $version, $wanted)
                : $this->link($library, $catalog['files'], $wanted, $version);
        } catch (\Throwable $e) {
            // Библиотека уже создана и могла получить часть файлов. Оставить
            // её нельзя: пустая библиотека заняла бы slug, и повторный вызов
            // упирался бы в slug_taken навсегда. Наружу отдаём свой текст —
            // в исключении может лежать SQL с параметрами подключения
            $this->rollback($library);
            report($e);

            return [
                'error' => 'save_failed',
                'message' => 'Не удалось сохранить файлы библиотеки, она не создана. Проверьте права на запись в storage.',
            ];
        }

        if (!$result['added']) {
            $this->rollback($library);

            return ['error' => 'nothing_added', 'failed' => $result['failed']];
        }

        return [
            'library_id' => $library->id,
            'slug' => $library->slug,
            'package' => $info['name'],
            'version' => $version,
            'mode' => $mode,
            'added' => count($result['added']),
            'failed' => $result['failed'],
        ];
    }

    /**
     * Подключение ссылками: на диск ничего не пишется, поэтому ни бюджета
     * времени, ни лимита числа файлов здесь нет — они нужны только скачиванию.
     *
     * Форма ответа та же, что у CatalogDownloader::download(): вызывающему
     * не нужно знать, каким путём добавлялись файлы.
     *
     * @param  array<int, array<string, mixed>>  $catalogFiles
     * @param  array<int, string>  $names
     * @return array{added: array<int, string>, failed: array<int, array{file: string, reason: string}>}
     */
    private function link(Library $library, array $catalogFiles, array $names, string $version): array
    {
        // Ссылку собирает каталог, модель передаёт только имя файла:
        // подставить через параметр чужой адрес невозможно
        $byName = collect($catalogFiles)->keyBy('file');

        $rows = [];
        $added = [];
        $failed = [];

        foreach (array_values($names) as $name) {
            $meta = $byName->get($name);

            if (!$meta) {
                $failed[] = ['file' => $name, 'reason' => 'Файла нет в этой версии пакета.'];
                continue;
            }

            // Повтор имени в списке дал бы две одинаковые строки и два
            // <script> на странице: модель не видит, что уже добавлено
            if (in_array($name, $added, true)) {
                $failed[] = ['file' => $name, 'reason' => 'Уже есть в списке файлов.'];
                continue;
            }

            $rows[] = [
                'type' => $meta['type'],
                'source' => 'cdn',
                'url' => $meta['url'],
                'version' => $version,
            ];

            $added[] = $name;
        }

        // Библиотека здесь всегда только что создана, поэтому переносить
        // существующие строки не из чего: $rows — полный список
        if ($added) {
            $this->sync->sync($library, $rows);
        }

        return ['added' => $added, 'failed' => $failed];
    }

    /**
     * Откат: библиотеку убираем целиком — файлы с диска строго по фактическим
     * путям записей и саму строку. Тот же порядок, что в
     * LibraryController::rollbackLibrary(), и допустим он здесь по той же
     * причине: библиотека создана этим же вызовом.
     */
    private function rollback(Library $library): void
    {
        // load(), а не loadMissing(): sync() создаёт записи через связь,
        // и загруженная до него коллекция о них не знает
        $library->load('files');

        foreach ($library->files as $file) {
            if ($file->source === 'local' && filled($file->path) && Storage::disk('public')->exists($file->path)) {
                Storage::disk('public')->delete($file->path);
            }
        }

        $library->delete();
    }

    /**
     * Недоступность каталога нельзя схлопывать в «нет такого пакета»:
     * модель начнёт перебирать написания имени, а CdnjsClient кэширует
     * отказ на минуту — перебор даже не дойдёт до сети.
     *
     * @param  array<string, mixed>  $result  ответ CdnjsClient
     * @param  array<string, mixed>  $fallback  что вернуть, если каталог жив
     * @return array<string, mixed>
     */
    private function catalogFailure(array $result, array $fallback): array
    {
        return ($result['error'] ?? '') === 'catalog_unavailable'
            ? ['error' => 'catalog_unavailable', 'message' => 'Каталог cdnjs сейчас недоступен, повторите позже']
            : $fallback;
    }
}
