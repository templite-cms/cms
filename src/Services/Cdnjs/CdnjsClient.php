<?php

namespace Templite\Cms\Services\Cdnjs;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Единственное место, где CMS ходит в каталог cdnjs.
 *
 * Наружу отдаётся нормализованная структура, а не сырой ответ: тогда
 * второй источник (jsDelivr) можно будет добавить, не задев вызывающих.
 * Недоступность каталога возвращается флагом ok=false, а не исключением —
 * и UI, и ассистент должны показать внятное сообщение, а не 500.
 *
 * В кэш попадает только успех: разовый сбой сети иначе заморозил бы
 * ошибку на весь TTL, и «повторите позже» стало бы неправдой.
 */
class CdnjsClient
{
    private const API_URL = 'https://api.cdnjs.com/libraries';
    private const CDN_URL = 'https://cdnjs.cloudflare.com/ajax/libs';
    private const TIMEOUT = 15;

    /** Поиск и список версий меняются при выходе релиза, файлы версии неизменяемы */
    private const TTL_SEARCH = 3600;
    private const TTL_FILES = 86400;

    /** Отказы кэшируются ненадолго: без этого при лежащем каталоге каждый
     *  запрос ждёт таймаут, а с обычным TTL разовый сбой замораживал бы
     *  ошибку на час или сутки */
    private const TTL_FAILURE = 60;

    /** Библиотеке нужны только эти расширения; .d.ts, .map, .mjs лишь мусорят список */
    private const USEFUL_EXTENSIONS = ['js', 'css'];

    /**
     * @return array{ok: bool, results?: array<int, array{name: string, version: string, description: string}>, error?: string}
     */
    public function search(string $query, int $limit = 20): array
    {
        $query = trim($query);

        if ($query === '') {
            return ['ok' => true, 'results' => []];
        }

        $limit = max(1, min($limit, 50));
        $key = 'cms.cdnjs.search.' . md5($query) . ".{$limit}";

        if (is_array($cached = Cache::get($key))) {
            return $cached;
        }

        $res = $this->fetch('', [
            'search' => $query,
            'fields' => 'name,version,description',
            'limit' => $limit,
        ], 'results');

        // У поиска нет состояния «не найдено»: пустая выдача — это успех
        // с пустым списком, поэтому любая причина отказа означает недоступность
        if (!$res['ok']) {
            $result = ['ok' => false, 'error' => 'catalog_unavailable'];

            Cache::put($key, $result, self::TTL_FAILURE);

            return $result;
        }

        $data = $res['body'];
        $results = [];

        foreach ($this->arrayOf($data['results'] ?? null) as $item) {
            if (!is_array($item)) {
                continue;
            }

            $name = $this->stringOf($item['name'] ?? null);

            if ($name === '') {
                continue;
            }

            $results[] = [
                'name' => $name,
                'version' => $this->stringOf($item['version'] ?? null),
                'description' => $this->stringOf($item['description'] ?? null),
            ];
        }

        $result = ['ok' => true, 'results' => $results];

        Cache::put($key, $result, self::TTL_SEARCH);

        return $result;
    }

    /**
     * @return array{ok: bool, name?: string, filename?: string, latest?: string, versions?: array<int, string>, error?: string}
     */
    public function package(string $name): array
    {
        $name = trim($name);

        // Пустое имя ушло бы на /libraries/ — это эндпоинт списка: он ответит
        // 200 и отдаст весь каталог, который осел бы в кэше как «пакет»
        if ($name === '') {
            return ['ok' => false, 'error' => 'package_not_found'];
        }

        $key = 'cms.cdnjs.package.' . md5($name);

        if (is_array($cached = Cache::get($key))) {
            return $cached;
        }

        $res = $this->fetch(
            '/' . rawurlencode($name),
            ['fields' => 'name,filename,version,versions'],
            'versions'
        );

        if (!$res['ok']) {
            $result = [
                'ok' => false,
                'error' => $res['reason'] === 'unavailable' ? 'catalog_unavailable' : 'package_not_found',
            ];

            Cache::put($key, $result, self::TTL_FAILURE);

            return $result;
        }

        $data = $res['body'];
        $versions = [];

        // versions приходит от новых к старым — порядок сохраняем как есть
        foreach ($this->arrayOf($data['versions'] ?? null) as $version) {
            if (is_scalar($version)) {
                $versions[] = (string) $version;
            }
        }

        $result = [
            'ok' => true,
            // Имя чувствительно к регистру: Swiper, но bootstrap. Дальше по
            // цепочке используется только то, что вернул сам каталог
            'name' => $this->stringOf($data['name'] ?? null) ?: $name,
            'filename' => $this->stringOf($data['filename'] ?? null),
            'latest' => $this->stringOf($data['version'] ?? null),
            'versions' => $versions,
        ];

        Cache::put($key, $result, self::TTL_SEARCH);

        return $result;
    }

    /**
     * @return array{ok: bool, name?: string, version?: string, total?: int, files?: array<int, array{file: string, type: string, url: string, sri: ?string, recommended: bool}>, error?: string}
     */
    public function files(string $name, string $version): array
    {
        $name = trim($name);
        $version = trim($version);

        if ($name === '' || $version === '') {
            return ['ok' => false, 'error' => 'version_not_found'];
        }

        $key = 'cms.cdnjs.files.' . md5($name) . '.' . md5($version);

        if (is_array($cached = Cache::get($key))) {
            return $cached;
        }

        // Пакет спрашиваем первым: без его filename не посчитать recommended,
        // а список без флагов бесполезен — интерфейс свернёт его в пустоту,
        // а ассистент решит, что выбирать нечего. Заодно не тянем мегабайты
        // списка файлов, когда пакет и так недоступен
        $package = $this->package($name);

        // Свой отказ package() уже положил в кэш, повтор до сети не дойдёт —
        // класть его второй раз под ключом версии значило бы сложить два TTL
        if (!$package['ok']) {
            return ['ok' => false, 'error' => $package['error'] ?? 'package_not_found'];
        }

        $res = $this->fetch('/' . rawurlencode($name) . '/' . rawurlencode($version), [], 'files');

        if (!$res['ok']) {
            $result = [
                'ok' => false,
                'error' => $res['reason'] === 'unavailable' ? 'catalog_unavailable' : 'version_not_found',
            ];

            Cache::put($key, $result, self::TTL_FAILURE);

            return $result;
        }

        $data = $res['body'];
        $main = $package['filename'];
        // Парный стиль ищем по basename: у bootstrap главный файл лежит в js/,
        // а стиль — в css/, и сравнение по полному пути его не нашло бы
        $mainName = basename($main);
        $mainBase = $mainName !== '' ? preg_replace('/\.[^.]+$/', '', $mainName) : null;
        $sri = $this->arrayOf($data['sri'] ?? null);
        $all = $this->arrayOf($data['files'] ?? null);

        $files = [];

        foreach ($all as $file) {
            if (!is_scalar($file)) {
                continue;
            }

            $file = (string) $file;
            $type = $this->typeOf($file);

            if ($type === null) {
                continue;
            }

            $files[] = [
                'file' => $file,
                'type' => $type,
                'url' => $this->fileUrl($name, $version, $file),
                // SRI есть не у всех: у Bootstrap хэши для 22 файлов из 136
                'sri' => is_scalar($sri[$file] ?? null) ? (string) $sri[$file] : null,
                'recommended' => $this->isRecommended($file, $main, $mainBase),
            ];
        }

        $result = [
            'ok' => true,
            'name' => $name,
            'version' => $version,
            // Сколько файлов в версии всего: позволяет объяснить пустой список
            // («пакет состоит из 3364 файлов, но среди них нет js и css»),
            // а не показывать пустоту без причины
            'total' => count($all),
            'files' => $files,
        ];

        Cache::put($key, $result, self::TTL_FILES);

        return $result;
    }

    /**
     * Ссылка на конкретный файл пакета.
     *
     * Хост зашит константой и подставляется раньше любых данных, а имя файла
     * приходит из ответа каталога, а не от клиента, — увести ссылку на чужой
     * адрес через параметры нельзя.
     */
    public function fileUrl(string $name, string $version, string $file): string
    {
        return self::CDN_URL . '/' . $name . '/' . $version . '/' . ltrim($file, '/');
    }

    /**
     * Запрос к каталогу с нормализацией отказов.
     *
     * Причина отказа сохраняется, а не схлопывается: «каталог недоступен» и
     * «в каталоге такого нет» требуют от пользователя противоположных действий
     * — подождать против исправить название, — и вызывающий обязан их различать.
     *
     * @param  string  $expectKey  ключ, который в исправном ответе обязан быть массивом
     * @return array{ok: bool, reason?: string, body?: array}
     */
    private function fetch(string $path, array $query, string $expectKey): array
    {
        try {
            $res = Http::timeout(self::TIMEOUT)->acceptJson()->get(self::API_URL . $path, $query);
        } catch (ConnectionException $e) {
            // Нет DNS, нет исходящего интернета, TLS-таймаут. У коробочной
            // установки это самый частый отказ, и объекта ответа он не даёт вовсе
            return ['ok' => false, 'reason' => 'unavailable'];
        }

        // Тело не той формы при успешном коде — обычно заглушка CDN.
        // Это «каталог не в порядке», а не «пакета нет»
        if ($res->successful() && !is_array($res->json($expectKey))) {
            return ['ok' => false, 'reason' => 'unavailable'];
        }

        if (!$res->successful()) {
            // 429 и 408 приходят как клиентские коды, но означают «каталог
            // сейчас не отвечает», а не «пакета нет»: cdnjs стоит за Cloudflare
            // и лимитирует частоту, а поиск в интерфейсе шлёт запрос по мере ввода
            $unavailable = $res->serverError() || in_array($res->status(), [408, 429], true);

            return ['ok' => false, 'reason' => $unavailable ? 'unavailable' : 'not_found'];
        }

        return ['ok' => true, 'body' => $res->json() ?? []];
    }

    private function typeOf(string $file): ?string
    {
        $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));

        return in_array($ext, self::USEFUL_EXTENSIONS, true) ? $ext : null;
    }

    /**
     * Рекомендуем главный файл пакета и одноимённый ему CSS.
     * Флаг считается на сервере, чтобы UI и ассистент не разошлись в эвристике.
     */
    private function isRecommended(string $file, string $main, ?string $mainBase): bool
    {
        if ($main !== '' && $file === $main) {
            return true;
        }

        return $mainBase !== null && basename($file) === $mainBase . '.css';
    }

    /** Обходим только то, что действительно массив: иначе foreach бросит исключение. */
    private function arrayOf(mixed $value): array
    {
        return is_array($value) ? $value : [];
    }

    /** К строке приводим только скаляры: вложенный массив дал бы «Array to string conversion». */
    private function stringOf(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }
}
