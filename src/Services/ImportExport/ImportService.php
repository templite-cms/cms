<?php

namespace Templite\Cms\Services\ImportExport;

use Templite\Cms\Models\ExportImportLog;
use Templite\Cms\Models\{Block, BlockType, TemplatePage, Component, Action, Library,
    BlockPreset, Page, PageType, GlobalFieldPage, CmsConfig, City, Language,
    FileFolder, File};
use Illuminate\Support\Facades\{Auth, DB};
use ZipArchive;

/**
 * Сервис импорта сущностей из ZIP-архива.
 *
 * Поддерживает:
 * - Предварительный просмотр (upload): разбор ZIP, обнаружение конфликтов
 * - Полный импорт (import): создание/обновление сущностей с учётом решений по конфликтам
 *
 * Порядок импорта определяется массивом $importOrder для корректного
 * разрешения foreign key зависимостей.
 */
class ImportService
{
    /**
     * Порядок импорта сущностей (зависимости раньше зависимых).
     *
     * @var string[]
     */
    protected array $importOrder = [
        'language', 'block_type', 'cms_config', 'file_folder', 'file',
        'library', 'action', 'component',
        'block', 'preset', 'template',
        'page_type', 'global_field_page',
        'page', 'city',
    ];

    /**
     * Маппинг типа сущности на класс модели.
     *
     * @var array<string, class-string>
     */
    protected array $modelMap = [
        'language' => Language::class,
        'block_type' => BlockType::class,
        'cms_config' => CmsConfig::class,
        'file_folder' => FileFolder::class,
        'file' => File::class,
        'library' => Library::class,
        'action' => Action::class,
        'component' => Component::class,
        'block' => Block::class,
        'preset' => BlockPreset::class,
        'template' => TemplatePage::class,
        'page_type' => PageType::class,
        'global_field_page' => GlobalFieldPage::class,
        'page' => Page::class,
        'city' => City::class,
    ];

    /**
     * Маппинг типа сущности на поле-идентификатор (для поиска существующих).
     *
     * @var array<string, string>
     */
    protected array $identifierFieldMap = [
        'block' => 'slug',
        'block_type' => 'slug',
        'template' => 'slug',
        'component' => 'slug',
        'action' => 'slug',
        'library' => 'slug',
        'preset' => 'slug',
        'page' => 'url',
        'page_type' => 'slug',
        'cms_config' => 'key',
        'city' => 'slug',
        'language' => 'code',
        'file' => 'path',
        'file_folder' => 'name',
        'global_field_page' => 'name',
    ];

    public function __construct(
        protected ConflictDetector $conflictDetector,
        protected MediaPacker $mediaPacker,
    ) {}

    /**
     * Загрузить и проанализировать ZIP-архив без импорта.
     *
     * Возвращает манифест, распарсенные данные и список конфликтов.
     *
     * @param string $zipPath Путь к ZIP-файлу
     * @return array{manifest: array, entities: array, conflicts: array}
     */
    public function upload(string $zipPath): array
    {
        $zip = new ZipArchive();
        if ($zip->open($zipPath) !== true) {
            throw new \RuntimeException("Не удалось открыть ZIP-архив: {$zipPath}");
        }

        $manifest = json_decode($zip->getFromName('manifest.json'), true);
        $entitiesData = $this->parseZip($zip);
        $conflicts = $this->conflictDetector->detect($manifest, $entitiesData);

        $zip->close();

        return [
            'manifest' => $manifest,
            'entities' => $entitiesData,
            'conflicts' => $conflicts,
        ];
    }

    /**
     * Выполнить полный импорт из ZIP-архива.
     *
     * @param string $zipPath Путь к ZIP-файлу
     * @param array $conflictActions Решения по конфликтам: ["type:identifier" => "skip"|"overwrite"|"copy"]
     * @return array{created: int, updated: int, skipped: int}
     *
     * @throws \Throwable При ошибке импорта (транзакция откатывается)
     */
    public function import(string $zipPath, array $conflictActions): array
    {
        $zip = new ZipArchive();
        if ($zip->open($zipPath) !== true) {
            throw new \RuntimeException("Не удалось открыть ZIP-архив: {$zipPath}");
        }

        $manifest = json_decode($zip->getFromName('manifest.json'), true);
        $entitiesData = $this->parseZip($zip);

        // Распаковать медиа-файлы в storage
        $this->mediaPacker->unpack($zip);

        $ctx = new ImportContext();
        $ctx->setConflictActions($conflictActions);

        $stats = ['created' => 0, 'updated' => 0, 'skipped' => 0];

        DB::beginTransaction();
        try {
            // Предзагрузить существующие сущности в контекст
            $this->preloadExistingEntities($ctx, $entitiesData);

            foreach ($this->importOrder as $type) {
                if (!isset($entitiesData[$type])) {
                    continue;
                }

                $modelClass = $this->modelMap[$type];

                foreach ($entitiesData[$type] as $identifier => $data) {
                    $action = $ctx->getConflictAction($type, $identifier);
                    $existing = $ctx->resolve($type, $identifier);

                    if ($existing && $action === 'skip') {
                        $stats['skipped']++;
                        continue;
                    }

                    // Сохранить _zip_prefix до передачи в модель
                    $zipPrefix = $data['_zip_prefix'] ?? null;
                    unset($data['_zip_prefix']);

                    if ($existing && $action === 'copy') {
                        $data = $this->makeUnique($type, $data);
                    }

                    $model = $modelClass::fromImportArray($data, $ctx);
                    $ctx->register($type, $identifier, $model);

                    // Извлечь файлы кода из ZIP (формат v2.0)
                    if ($zipPrefix && method_exists($modelClass, 'importCodeFromZip')) {
                        $modelClass::importCodeFromZip($model, $zip, $zipPrefix);
                    }

                    if ($existing && $action !== 'copy') {
                        $stats['updated']++;
                    } else {
                        $stats['created']++;
                    }
                }
            }

            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();

            ExportImportLog::create([
                'type' => 'import',
                'manager_id' => auth()->id(),
                'filename' => basename($zipPath),
                'entity_summary' => $manifest['entity_summary'] ?? [],
                'conflicts' => $conflictActions,
                'status' => 'failed',
                'error_message' => $e->getMessage(),
                'file_size' => filesize($zipPath),
            ]);

            throw $e;
        }

        $zip->close();

        ExportImportLog::create([
            'type' => 'import',
            'manager_id' => auth()->id(),
            'filename' => basename($zipPath),
            'entity_summary' => $manifest['entity_summary'] ?? [],
            'conflicts' => $conflictActions,
            'status' => 'completed',
            'file_size' => filesize($zipPath),
        ]);

        return $stats;
    }

    /**
     * Разобрать содержимое ZIP-архива в структуру данных.
     *
     * Поддерживает два формата:
     * - v2.0: Директорная структура {type}/{slug}/meta.json + файлы кода
     * - v1.0: Плоские JSON {type}/{slug}.json (обратная совместимость)
     * - Коллективные JSON-файлы (settings/cms_config.json, и т.д.)
     *
     * @param ZipArchive $zip Открытый ZIP-архив
     * @return array<string, array<string, array>> type => [identifier => data]
     */
    protected function parseZip(ZipArchive $zip): array
    {
        $data = [];
        $dirTypeMap = [
            'blocks' => 'block',
            'block_types' => 'block_type',
            'templates' => 'template',
            'components' => 'component',
            'actions' => 'action',
            'libraries' => 'library',
            'presets' => 'preset',
            'pages' => 'page',
            'page_types' => 'page_type',
        ];

        // Сначала ищем meta.json в директориях (формат v2.0)
        $processedDirs = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);

            // Паттерн: {type}/{slug}/meta.json
            if (!str_ends_with($name, '/meta.json')) {
                continue;
            }

            $parts = explode('/', $name);
            if (count($parts) !== 3) {
                continue;
            }

            $dir = $parts[0];
            $slug = $parts[1];

            if (!isset($dirTypeMap[$dir])) {
                continue;
            }

            $json = json_decode($zip->getFromIndex($i), true);
            if (!$json) {
                continue;
            }

            $type = $dirTypeMap[$dir];
            $identifier = $json['slug'] ?? $json['url'] ?? $json['key'] ?? $slug;

            // Сохраняем путь в ZIP для последующего извлечения файлов кода
            $json['_zip_prefix'] = "{$dir}/{$slug}/";

            $data[$type][$identifier] = $json;
            $processedDirs["{$dir}/{$slug}"] = true;
        }

        // Затем ищем плоские JSON-файлы (формат v1.0 или сущности без кода)
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if (!str_ends_with($name, '.json') || $name === 'manifest.json') {
                continue;
            }

            // Пропускаем meta.json (уже обработаны выше)
            if (str_ends_with($name, '/meta.json')) {
                continue;
            }

            $parts = explode('/', $name);
            $dir = $parts[0] ?? '';

            // Пропускаем если директория уже обработана как v2.0
            $fileBaseName = pathinfo($name, PATHINFO_FILENAME);
            if (isset($processedDirs["{$dir}/{$fileBaseName}"])) {
                continue;
            }

            if (isset($dirTypeMap[$dir])) {
                $json = json_decode($zip->getFromIndex($i), true);
                if (!$json) {
                    continue;
                }
                $identifier = $json['slug'] ?? $json['url'] ?? $json['key'] ?? $fileBaseName;
                $data[$dirTypeMap[$dir]][$identifier] = $json;
            }
        }

        // Коллективные JSON-файлы (массив сущностей в одном файле)
        $collectiveMap = [
            'settings/cms_config.json' => ['type' => 'cms_config', 'id_field' => 'key'],
            'global_fields/global_fields.json' => ['type' => 'global_field_page', 'id_field' => 'name'],
            'cities/cities.json' => ['type' => 'city', 'id_field' => 'slug'],
            'languages/languages.json' => ['type' => 'language', 'id_field' => 'code'],
            'media/files.json' => ['type' => 'file', 'id_field' => 'path'],
            'media/folders.json' => ['type' => 'file_folder', 'id_field' => 'name'],
        ];

        foreach ($collectiveMap as $path => $config) {
            $content = $zip->getFromName($path);
            if ($content === false) {
                continue;
            }

            $items = json_decode($content, true);
            if (!is_array($items)) {
                continue;
            }

            foreach ($items as $item) {
                $identifier = $item[$config['id_field']] ?? null;
                if ($identifier) {
                    $data[$config['type']][$identifier] = $item;
                }
            }
        }

        return $data;
    }

    /**
     * Предзагрузить существующие сущности в ImportContext.
     *
     * Batch-запросы по каждому типу для эффективного определения конфликтов.
     *
     * @param ImportContext $ctx Контекст импорта
     * @param array $entitiesData Данные сущностей из ZIP
     */
    protected function preloadExistingEntities(ImportContext $ctx, array $entitiesData): void
    {
        foreach ($entitiesData as $type => $items) {
            if (!isset($this->modelMap[$type]) || !isset($this->identifierFieldMap[$type])) {
                continue;
            }

            $modelClass = $this->modelMap[$type];
            $field = $this->identifierFieldMap[$type];
            $identifiers = array_keys($items);

            $existing = $modelClass::whereIn($field, $identifiers)->get();
            foreach ($existing as $model) {
                $ctx->register($type, $model->{$field}, $model);
            }
        }
    }

    /**
     * Сделать данные сущности уникальными (для действия "copy").
     *
     * Добавляет суффикс к slug/url/key и " (Copy)" к имени.
     *
     * @param string $type Тип сущности
     * @param array $data Данные сущности
     * @return array Модифицированные данные
     */
    protected function makeUnique(string $type, array $data): array
    {
        $suffix = '-copy-' . substr(md5(microtime()), 0, 6);

        if (isset($data['slug'])) {
            $data['slug'] .= $suffix;
            $data['name'] = ($data['name'] ?? '') . ' (Copy)';
        } elseif (isset($data['url'])) {
            $data['url'] .= $suffix;
            $data['title'] = ($data['title'] ?? '') . ' (Copy)';
        } elseif (isset($data['key'])) {
            $data['key'] .= $suffix;
        } elseif (isset($data['code'])) {
            $data['code'] .= $suffix;
        }

        return $data;
    }
}
