<?php

namespace Templite\Cms\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\HtmlString;
use Templite\Cms\Concerns\HasExportable;
use Templite\Cms\Contracts\Exportable;
use Templite\Cms\Services\ImportExport\ImportContext;
use Templite\Cms\Traits\HasFieldable;
use Templite\Cms\Traits\HasFiles;

class Block extends Model implements Exportable
{
    use HasFieldable, HasFiles, HasExportable;

    protected $fillable = [
        'name',
        'slug',
        'description',
        'block_type_id',
        'source',
        'path',
        'controller',
        'tags',
        'screen',
        'order',
        'auto_marker',
    ];

    protected $casts = [
        'auto_marker' => 'boolean',
    ];

    /**
     * Метка корня для ручной расстановки в шаблоне: <section {{ $block->marker }}>.
     * Нужна, когда автометка выключена или корень не первый элемент шаблона.
     */
    public function getMarkerAttribute(): HtmlString
    {
        return new HtmlString('data-block="' . e($this->slug) . '"');
    }

    // --- Relationships ---

    public function blockType(): BelongsTo
    {
        return $this->belongsTo(BlockType::class);
    }

    // fields(), rootFields(), экспорт/импорт полей — из HasFieldable

    public function blockActions(): HasMany
    {
        return $this->hasMany(BlockAction::class)->orderBy('order');
    }

    /**
     * Алиас для blockActions() — для удобства with('actions') в контроллерах.
     */
    public function actions(): HasMany
    {
        return $this->blockActions();
    }

    public function pageBlocks(): HasMany
    {
        return $this->hasMany(PageBlock::class);
    }

    public function presets(): HasMany
    {
        return $this->hasMany(BlockPreset::class)->orderBy('order');
    }

    public function libraries(): BelongsToMany
    {
        return $this->belongsToMany(Library::class, 'block_library');
    }

    // --- Scopes ---

    public function scopeBySource($query, string $source)
    {
        return $query->where('source', $source);
    }

    public function scopeByType($query, int $blockTypeId)
    {
        return $query->where('block_type_id', $blockTypeId);
    }

    // --- Exportable ---

    public function getExportType(): string { return 'block'; }
    public function getExportIdentifier(): string { return $this->slug; }

    public function getDependencies(): array
    {
        $deps = [];
        if ($this->blockType) {
            $deps[] = $this->blockType;
        }
        // Collect actual Action models through blockActions pivot
        foreach ($this->blockActions as $ba) {
            if ($ba->action) {
                $deps[] = $ba->action;
            }
        }
        foreach ($this->libraries as $lib) {
            $deps[] = $lib;
        }
        if ($this->screenshot) {
            $deps[] = $this->screenshot;
        }
        return $deps;
    }

    public function toExportArray(): array
    {
        return [
            'name' => $this->name,
            'slug' => $this->slug,
            'description' => $this->description,
            'block_type_slug' => $this->blockType?->slug,
            'source' => 'database',
            'tags' => $this->tags,
            'auto_marker' => $this->auto_marker,
            'order' => $this->order,
            'screen_path' => $this->screenshot?->path,
            'library_slugs' => $this->libraries->pluck('slug')->toArray(),
            'actions' => $this->blockActions->map(fn ($ba) => [
                'action_slug' => $ba->action?->slug,
                'params' => $ba->params,
                'order' => $ba->order,
            ])->toArray(),
            'fields' => $this->exportFields(),
        ];
    }

    public function getExportMediaFiles(): array
    {
        return $this->screenshot ? $this->screenshot->getExportMediaFiles() : [];
    }

    /**
     * Файлы кода блока для экспорта в ZIP (реальные файлы, не JSON-строки).
     *
     * @return array<string, string> имя файла в ZIP => абсолютный путь на диске
     */
    public function getCodeFilesForExport(): array
    {
        $basePath = storage_path("cms/blocks/" . basename($this->slug));
        if (!is_dir($basePath)) {
            return [];
        }

        $files = [];
        foreach (['template.blade.php', 'style.scss', 'script.js'] as $file) {
            $fullPath = "{$basePath}/{$file}";
            if (file_exists($fullPath)) {
                $files[$file] = $fullPath;
            }
        }
        return $files;
    }

    public static function fromImportArray(array $data, ImportContext $ctx): static
    {
        $blockTypeId = $ctx->resolveId('block_type', $data['block_type_slug'] ?? '');
        $screenId = isset($data['screen_path'])
            ? $ctx->resolveId('file', $data['screen_path']) : null;

        $block = static::updateOrCreate(
            ['slug' => $data['slug']],
            [
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'block_type_id' => $blockTypeId,
                'source' => 'database',
                'tags' => $data['tags'] ?? null,
                // Экспорт до замены обёртки нёс no_wrapper
                'auto_marker' => $data['auto_marker'] ?? !($data['no_wrapper'] ?? false),
                'order' => $data['order'] ?? 0,
                'screen' => $screenId,
            ]
        );

        // Синхронизация библиотек
        $libIds = \collect($data['library_slugs'] ?? [])
            ->map(fn ($slug) => $ctx->resolveId('library', $slug))
            ->filter()->toArray();
        $block->libraries()->sync($libIds);

        // Синхронизация actions
        $block->blockActions()->delete();
        foreach ($data['actions'] ?? [] as $ba) {
            $actionId = $ctx->resolveId('action', $ba['action_slug'] ?? '');
            if ($actionId) {
                $block->blockActions()->create([
                    'action_id' => $actionId,
                    'params' => $ba['params'] ?? null,
                    'order' => $ba['order'] ?? 0,
                ]);
            }
        }

        $block->importFields($data['fields'] ?? []);

        // Обратная совместимость с v1.0 (код как JSON-строки)
        if (isset($data['code']) && is_array($data['code'])) {
            static::importCodeLegacy($block, $data['code']);
        }

        return $block;
    }

    /**
     * Импорт файлов кода из ZIP-архива.
     * Вызывается ImportService после fromImportArray().
     */
    public static function importCodeFromZip(self $block, \ZipArchive $zip, string $zipPrefix): void
    {
        $basePath = storage_path("cms/blocks/" . basename($block->slug));

        $found = false;
        foreach (['template.blade.php', 'style.scss', 'script.js'] as $file) {
            $content = $zip->getFromName("{$zipPrefix}{$file}");
            if ($content !== false) {
                if (!is_dir($basePath)) {
                    mkdir($basePath, 0755, true);
                }
                file_put_contents("{$basePath}/{$file}", $content);
                $found = true;
            }
        }

        if ($found) {
            $block->update(['source' => 'database', 'path' => null]);
        }
    }

    /**
     * Обратная совместимость: импорт кода из JSON-строк (формат v1.0).
     */
    protected static function importCodeLegacy(self $block, array $code): void
    {
        $basePath = storage_path("cms/blocks/" . basename($block->slug));
        if (!is_dir($basePath)) {
            mkdir($basePath, 0755, true);
        }
        if ($code['template'] ?? null) {
            file_put_contents("{$basePath}/template.blade.php", $code['template']);
        }
        if ($code['style'] ?? null) {
            file_put_contents("{$basePath}/style.scss", $code['style']);
        }
        if ($code['script'] ?? null) {
            file_put_contents("{$basePath}/script.js", $code['script']);
        }
        $block->update(['source' => 'database', 'path' => null]);
    }
}
