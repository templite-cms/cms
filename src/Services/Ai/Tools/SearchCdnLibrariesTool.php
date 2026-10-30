<?php

namespace Templite\Cms\Services\Ai\Tools;

use Templite\Cms\Services\Ai\EditorContext;
use Templite\Cms\Services\Cdnjs\CdnjsClient;

class SearchCdnLibrariesTool implements Tool
{
    public function __construct(private readonly CdnjsClient $cdnjs)
    {
    }

    public function name(): string
    {
        return 'search_cdn_libraries';
    }

    public function definition(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'query' => ['type' => 'string', 'description' => 'Название библиотеки: swiper, bootstrap, gsap'],
                'package' => ['type' => 'string', 'description' => 'Точное имя пакета из результатов поиска — вернёт файлы версии'],
                'version' => ['type' => 'string', 'description' => 'Версия пакета; без неё берётся последняя'],
            ],
        ];
    }

    public function execute(array $args, ?EditorContext $ctx): array
    {
        $package = trim((string) ($args['package'] ?? ''));

        // Без package это поиск, с package — список файлов конкретной версии.
        // Модель должна увидеть реальные имена файлов, а не угадывать их
        if ($package === '') {
            $result = $this->cdnjs->search(trim((string) ($args['query'] ?? '')));

            return ($result['ok'] ?? false)
                ? ['results' => $result['results']]
                : ['error' => 'catalog_unavailable', 'message' => 'Каталог cdnjs сейчас недоступен, повторите позже'];
        }

        $info = $this->cdnjs->package($package);

        if (!($info['ok'] ?? false)) {
            return $this->catalogFailure($info, ['error' => 'package_not_found', 'package' => $package]);
        }

        $version = trim((string) ($args['version'] ?? '')) ?: $info['latest'];
        $files = $this->cdnjs->files($info['name'], $version);

        if (!($files['ok'] ?? false)) {
            return $this->catalogFailure($files, [
                'error' => 'version_not_found',
                'package' => $info['name'],
                'version' => $version,
            ]);
        }

        return [
            // Имя возвращаем то, что дал каталог: оно чувствительно к регистру
            'package' => $info['name'],
            'version' => $version,
            'latest' => $info['latest'],
            // Без total модель не поймёт пустой список: у пакетов из одних
            // картинок (simple-icons, twemoji) после фильтрации не остаётся
            // ничего, хотя сам пакет существует
            'total_files_in_version' => $files['total'],
            'files' => array_map(fn ($f) => [
                'file' => $f['file'],
                'type' => $f['type'],
                'recommended' => $f['recommended'],
            ], $files['files']),
        ];
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
