<?php

namespace Templite\Cms\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Templite\Cms\Concerns\HasExportable;
use Templite\Cms\Contracts\Exportable;
use Templite\Cms\Services\ImportExport\ImportContext;
use Templite\Cms\Traits\HasFiles;

class Action extends Model implements Exportable
{
    use HasExportable, HasFiles;

    protected $fillable = [
        'name',
        'slug',
        'class_name',
        'source',
        'params',
        'returns',
        'description',
        'code_hash',
        'screen',
        'allow_http',
    ];

    protected $casts = [
        'params' => 'json',
        'returns' => 'json',
        'allow_http' => 'boolean',
    ];

    public function blockActions(): HasMany
    {
        return $this->hasMany(BlockAction::class);
    }

    public function scopeBySource($query, string $source)
    {
        return $query->where('source', $source);
    }

    // --- Exportable ---

    public function getExportType(): string { return 'action'; }
    public function getExportIdentifier(): string { return $this->slug; }
    public function getDependencies(): array { return []; }

    public function toExportArray(): array
    {
        return [
            'name' => $this->name,
            'slug' => $this->slug,
            'class_name' => $this->class_name,
            'source' => 'database',
            'params' => $this->params,
            'returns' => $this->returns,
            'description' => $this->description,
            'allow_http' => $this->allow_http,
        ];
    }

    public static function fromImportArray(array $data, ImportContext $ctx): static
    {
        return static::updateOrCreate(['slug' => $data['slug']], $data);
    }

    /**
     * PHP-файл экшена для экспорта в ZIP.
     *
     * @return array<string, string> имя файла в ZIP => абсолютный путь на диске
     */
    public function getCodeFilesForExport(): array
    {
        $filePath = storage_path("cms/actions/" . basename($this->slug) . ".php");
        if (!file_exists($filePath)) {
            return [];
        }

        return ["{$this->slug}.php" => $filePath];
    }

    /**
     * Импорт PHP-файла экшена из ZIP-архива.
     * Вызывается ImportService после fromImportArray().
     */
    public static function importCodeFromZip(self $action, \ZipArchive $zip, string $zipPrefix): void
    {
        $actionsDir = storage_path('cms/actions');
        if (!is_dir($actionsDir)) {
            mkdir($actionsDir, 0755, true);
        }

        // Ищем PHP-файл в директории action'а в ZIP
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if (str_starts_with($name, $zipPrefix) && str_ends_with($name, '.php')) {
                $content = $zip->getFromIndex($i);
                if ($content !== false) {
                    file_put_contents("{$actionsDir}/" . basename($action->slug) . ".php", $content);
                    $action->update(['source' => 'database']);
                }
                break;
            }
        }
    }
}
