<?php

namespace Templite\Cms\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Templite\Cms\Concerns\HasExportable;
use Templite\Cms\Contracts\Exportable;
use Templite\Cms\Services\ImportExport\ImportContext;

class Library extends Model implements Exportable
{
    use HasExportable;
    protected $fillable = [
        'name',
        'slug',
        'version',
        'description',
        'js_file',
        'css_file',
        'js_cdn',
        'css_cdn',
        'load_strategy',
        'sort_order',
        'active',
    ];

    protected $casts = [
        'active' => 'boolean',
        'sort_order' => 'integer',
    ];

    // --- Relationships ---

    public function blocks(): BelongsToMany
    {
        return $this->belongsToMany(Block::class, 'block_library');
    }

    public function templatePages(): BelongsToMany
    {
        return $this->belongsToMany(TemplatePage::class, 'template_page_library');
    }

    // --- Scopes ---

    public function scopeActive($query)
    {
        return $query->where('active', true);
    }

    // --- Exportable ---

    public function getExportType(): string { return 'library'; }
    public function getExportIdentifier(): string { return $this->slug; }
    public function getDependencies(): array { return []; }

    public function toExportArray(): array
    {
        return [
            'name' => $this->name,
            'slug' => $this->slug,
            'version' => $this->version,
            'description' => $this->description,
            'js_file_name' => $this->js_file ? basename($this->js_file) : null,
            'css_file_name' => $this->css_file ? basename($this->css_file) : null,
            'js_cdn' => $this->js_cdn,
            'css_cdn' => $this->css_cdn,
            'load_strategy' => $this->load_strategy,
            'sort_order' => $this->sort_order,
            'active' => $this->active,
        ];
    }

    public static function fromImportArray(array $data, ImportContext $ctx): static
    {
        $attrs = [
            'name' => $data['name'],
            'version' => $data['version'] ?? null,
            'description' => $data['description'] ?? null,
            'js_cdn' => $data['js_cdn'] ?? null,
            'css_cdn' => $data['css_cdn'] ?? null,
            'load_strategy' => $data['load_strategy'] ?? 'defer',
            'sort_order' => $data['sort_order'] ?? 0,
            'active' => $data['active'] ?? true,
        ];

        // Обратная совместимость с v1.0 (полные пути в json)
        if (isset($data['js_file'])) {
            $attrs['js_file'] = $data['js_file'];
        }
        if (isset($data['css_file'])) {
            $attrs['css_file'] = $data['css_file'];
        }

        return static::updateOrCreate(['slug' => $data['slug']], $attrs);
    }

    /**
     * Локальные JS/CSS файлы библиотеки для экспорта.
     *
     * @return array<string, string> имя файла в ZIP => абсолютный путь на диске
     */
    public function getCodeFilesForExport(): array
    {
        $files = [];
        $storage = \Illuminate\Support\Facades\Storage::disk('public');

        if ($this->js_file && $storage->exists($this->js_file)) {
            $files[basename($this->js_file)] = $storage->path($this->js_file);
        }
        if ($this->css_file && $storage->exists($this->css_file)) {
            $files[basename($this->css_file)] = $storage->path($this->css_file);
        }

        return $files;
    }

    /**
     * Импорт локальных JS/CSS файлов из ZIP-архива.
     * Вызывается ImportService после fromImportArray().
     */
    public static function importCodeFromZip(self $library, \ZipArchive $zip, string $zipPrefix): void
    {
        $storage = \Illuminate\Support\Facades\Storage::disk('public');
        $dir = "cms/libraries/{$library->slug}";
        $updateData = [];

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if (!str_starts_with($name, $zipPrefix) || str_ends_with($name, '/') || str_ends_with($name, 'meta.json')) {
                continue;
            }

            $fileName = basename($name);
            $content = $zip->getFromIndex($i);
            if ($content === false) {
                continue;
            }

            $targetPath = "{$dir}/{$fileName}";
            $storage->put($targetPath, $content);

            if (str_ends_with($fileName, '.js')) {
                $updateData['js_file'] = $targetPath;
            } elseif (str_ends_with($fileName, '.css')) {
                $updateData['css_file'] = $targetPath;
            }
        }

        if (!empty($updateData)) {
            $library->update($updateData);
        }
    }
}
