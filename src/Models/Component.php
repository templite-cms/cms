<?php

namespace Templite\Cms\Models;

use Illuminate\Database\Eloquent\Model;
use Templite\Cms\Concerns\HasExportable;
use Templite\Cms\Contracts\Exportable;
use Templite\Cms\Services\ImportExport\ImportContext;

class Component extends Model implements Exportable
{
    use HasExportable;
    protected $fillable = [
        'name',
        'slug',
        'source',
        'params',
        'description',
    ];

    protected $casts = [
        'params' => 'json',
    ];

    public function scopeBySource($query, string $source)
    {
        return $query->where('source', $source);
    }

    // --- Exportable ---

    public function getExportType(): string { return 'component'; }
    public function getExportIdentifier(): string { return $this->slug; }
    public function getDependencies(): array { return []; }

    public function toExportArray(): array
    {
        return [
            'name' => $this->name,
            'slug' => $this->slug,
            'source' => 'database',
            'params' => $this->params,
            'description' => $this->description,
        ];
    }

    public static function fromImportArray(array $data, ImportContext $ctx): static
    {
        return static::updateOrCreate(['slug' => $data['slug']], $data);
    }

    /**
     * Файлы кода компонента для экспорта в ZIP.
     *
     * @return array<string, string> имя файла в ZIP => абсолютный путь на диске
     */
    public function getCodeFilesForExport(): array
    {
        $basePath = storage_path("cms/components/" . basename($this->slug));
        if (!is_dir($basePath)) {
            return [];
        }

        $files = [];
        foreach (['index.blade.php', 'style.scss', 'script.js'] as $file) {
            $fullPath = "{$basePath}/{$file}";
            if (file_exists($fullPath)) {
                $files[$file] = $fullPath;
            }
        }
        return $files;
    }

    /**
     * Импорт файлов кода из ZIP-архива.
     * Вызывается ImportService после fromImportArray().
     */
    public static function importCodeFromZip(self $component, \ZipArchive $zip, string $zipPrefix): void
    {
        $basePath = storage_path("cms/components/" . basename($component->slug));

        foreach (['index.blade.php', 'style.scss', 'script.js'] as $file) {
            $content = $zip->getFromName("{$zipPrefix}{$file}");
            if ($content !== false) {
                if (!is_dir($basePath)) {
                    mkdir($basePath, 0755, true);
                }
                file_put_contents("{$basePath}/{$file}", $content);
            }
        }

        $component->update(['source' => 'database']);
    }
}
