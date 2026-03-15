<?php

namespace Templite\Cms\Services\ImportExport;

use Templite\Cms\Contracts\Exportable;
use Templite\Cms\Models\ExportImportLog;
use Illuminate\Support\Facades\Auth;
use ZipArchive;

class ExportService
{
    public function __construct(
        protected DependencyResolver $resolver,
        protected MediaPacker $mediaPacker,
    ) {}

    /**
     * @param Exportable[] $selected
     * @return array{entities: Exportable[], summary: array}
     */
    public function preview(array $selected): array
    {
        $resolved = $this->resolver->resolve($selected);
        return [
            'entities' => $resolved,
            'summary' => DependencyResolver::summarize($resolved),
        ];
    }

    /**
     * @param Exportable[] $entities Already resolved list
     * @return string Path to ZIP file
     */
    public function export(array $entities): string
    {
        $filename = 'export-' . date('Y-m-d-His') . '.zip';
        $zipPath = storage_path("app/exports/{$filename}");

        if (!is_dir(dirname($zipPath))) {
            mkdir(dirname($zipPath), 0755, true);
        }

        $zip = new ZipArchive();
        $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);

        $grouped = [];
        $mediaFiles = [];
        foreach ($entities as $entity) {
            $type = $entity->getExportType();
            $identifier = $entity->getExportIdentifier();
            $grouped[$type][$identifier] = $entity->toExportArray();

            if (method_exists($entity, 'getExportMediaFiles')) {
                $mediaFiles = array_merge($mediaFiles, $entity->getExportMediaFiles());
            }
        }

        // Types that get collected into a single JSON file
        $collectiveTypes = [
            'cms_config' => 'settings/cms_config.json',
            'global_field_page' => 'global_fields/global_fields.json',
            'city' => 'cities/cities.json',
            'language' => 'languages/languages.json',
            'file' => 'media/files.json',
            'file_folder' => 'media/folders.json',
        ];

        // Types that get individual JSON files per entity
        $individualDirMap = [
            'block' => 'blocks',
            'block_type' => 'block_types',
            'template' => 'templates',
            'component' => 'components',
            'action' => 'actions',
            'library' => 'libraries',
            'preset' => 'presets',
            'page' => 'pages',
            'page_type' => 'page_types',
        ];

        foreach ($grouped as $type => $items) {
            if (isset($collectiveTypes[$type])) {
                $zip->addFromString(
                    $collectiveTypes[$type],
                    json_encode(array_values($items), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
                );
            } elseif (isset($individualDirMap[$type])) {
                $dir = $individualDirMap[$type];
                foreach ($items as $identifier => $data) {
                    $safeName = str_replace(['/', '\\'], '-', $identifier);

                    // Найти оригинальную модель для проверки getCodeFilesForExport
                    $entity = $this->findEntity($entities, $type, $identifier);
                    $codeFiles = ($entity && method_exists($entity, 'getCodeFilesForExport'))
                        ? $entity->getCodeFilesForExport()
                        : [];

                    if (!empty($codeFiles)) {
                        // Директорная структура: {type}/{slug}/meta.json + файлы кода
                        $zip->addFromString(
                            "{$dir}/{$safeName}/meta.json",
                            json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
                        );
                        foreach ($codeFiles as $fileName => $diskPath) {
                            if (file_exists($diskPath)) {
                                $zip->addFile($diskPath, "{$dir}/{$safeName}/{$fileName}");
                            }
                        }
                    } else {
                        // Плоский JSON (нет файлов кода)
                        $zip->addFromString(
                            "{$dir}/{$safeName}.json",
                            json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
                        );
                    }
                }
            }
        }

        // Manifest
        $summary = DependencyResolver::summarize($entities);
        $manifest = [
            'version' => '2.0',
            'created_at' => now()->toIso8601String(),
            'manager' => auth()->user()?->login ?? 'system',
            'entity_summary' => $summary,
        ];
        $zip->addFromString('manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        // Pack media files
        $this->mediaPacker->pack($zip, $mediaFiles);

        $zip->close();

        // Log the export
        ExportImportLog::create([
            'type' => 'export',
            'manager_id' => auth()->id(),
            'filename' => $filename,
            'entity_summary' => $summary,
            'status' => 'completed',
            'file_size' => filesize($zipPath),
        ]);

        return $zipPath;
    }

    /**
     * Найти оригинальную Exportable-модель по type и identifier.
     *
     * @param Exportable[] $entities
     */
    protected function findEntity(array $entities, string $type, string $identifier): ?Exportable
    {
        foreach ($entities as $entity) {
            if ($entity->getExportType() === $type && $entity->getExportIdentifier() === $identifier) {
                return $entity;
            }
        }
        return null;
    }
}
