<?php

namespace Templite\Cms\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
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
        'no_wrapper',
    ];

    protected $casts = [
        'no_wrapper' => 'boolean',
    ];

    // --- Relationships ---

    public function blockType(): BelongsTo
    {
        return $this->belongsTo(BlockType::class);
    }

    public function tabs(): HasMany
    {
        return $this->hasMany(BlockTab::class)->orderBy('order');
    }

    public function sections(): HasMany
    {
        return $this->hasMany(BlockSection::class)->orderBy('order');
    }

    public function fields(): HasMany
    {
        return $this->hasMany(BlockField::class)->orderBy('order');
    }

    public function rootFields(): HasMany
    {
        return $this->hasMany(BlockField::class)->whereNull('parent_id')->orderBy('order');
    }

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
            'no_wrapper' => $this->no_wrapper,
            'order' => $this->order,
            'screen_path' => $this->screenshot?->path,
            'library_slugs' => $this->libraries->pluck('slug')->toArray(),
            'actions' => $this->blockActions->map(fn ($ba) => [
                'action_slug' => $ba->action?->slug,
                'params' => $ba->params,
                'order' => $ba->order,
            ])->toArray(),
            'tabs' => $this->exportFieldableTabs(),
            'fields' => $this->exportOrphanFields(),
        ];
    }

    public function getExportMediaFiles(): array
    {
        return $this->screenshot ? $this->screenshot->getExportMediaFiles() : [];
    }

    /**
     * Экспорт иерархии tabs -> sections -> fields для данного блока.
     */
    public function exportFieldableTabs(): array
    {
        $tabs = $this->tabs()
            ->orderBy('order')
            ->with(['sections.fields.children', 'fields.children'])
            ->get();

        return $tabs->map(function ($tab) {
            return [
                'name' => $tab->name,
                'order' => $tab->order,
                'columns' => $tab->columns,
                'column_widths' => $tab->column_widths,
                'sections' => $tab->sections->sortBy('order')->map(fn ($s) => [
                    'name' => $s->name,
                    'order' => $s->order,
                    'column_index' => $s->column_index,
                    'fields' => $this->exportFields($s->fields),
                ])->values()->toArray(),
                'fields' => $this->exportFields(
                    $tab->fields->whereNull('block_section_id')
                ),
            ];
        })->toArray();
    }

    /**
     * Рекурсивный экспорт полей с дочерними.
     *
     * @param \Illuminate\Support\Collection $fields
     * @return array
     */
    public function exportFields($fields): array
    {
        return $fields->sortBy('order')->map(function ($f) {
            $arr = [
                'name' => $f->name,
                'key' => $f->key,
                'type' => $f->type,
                'default_value' => $f->default_value,
                'data' => $f->data,
                'hint' => $f->hint,
                'order' => $f->order,
            ];
            if ($f->children->isNotEmpty()) {
                $arr['children'] = $this->exportFields($f->children);
            }
            return $arr;
        })->values()->toArray();
    }

    /**
     * Экспорт полей, не привязанных к табам (orphan fields).
     */
    public function exportOrphanFields(): array
    {
        $fields = $this->fields()
            ->whereNull('block_tab_id')
            ->whereNull('parent_id')
            ->with('children')
            ->orderBy('order')
            ->get();

        return $this->exportFields($fields);
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
                'no_wrapper' => $data['no_wrapper'] ?? false,
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

        // Импорт tabs/sections/fields
        static::importFieldableTabs($block, $data['tabs'] ?? []);

        // Импорт полей без табов (orphan fields)
        if (!empty($data['fields'])) {
            static::importOrphanFields($block, $data['fields']);
        }

        // Обратная совместимость с v1.0 (код как JSON-строки)
        if (isset($data['code']) && is_array($data['code'])) {
            static::importCodeLegacy($block, $data['code']);
        }

        return $block;
    }

    /**
     * Импорт иерархии tabs -> sections -> fields.
     * Удаляет существующие tabs (каскадно удалятся sections/fields).
     *
     * @param \Illuminate\Database\Eloquent\Model $owner Block или TemplatePage
     * @param array $tabs
     */
    public static function importFieldableTabs(Model $owner, array $tabs): void
    {
        // Удаление существующих tabs для данного owner
        if ($owner instanceof Block) {
            BlockTab::where('block_id', $owner->id)->delete();
        } else {
            $ownerClass = get_class($owner);
            BlockTab::where('fieldable_type', $ownerClass)
                ->where('fieldable_id', $owner->id)
                ->delete();
        }

        $blockId = $owner instanceof Block ? $owner->id : null;
        $ownerClass = get_class($owner);

        foreach ($tabs as $tabData) {
            $tab = BlockTab::create([
                'name' => $tabData['name'],
                'fieldable_type' => $ownerClass,
                'fieldable_id' => $owner->id,
                'block_id' => $blockId,
                'order' => $tabData['order'] ?? 0,
                'columns' => $tabData['columns'] ?? 1,
                'column_widths' => $tabData['column_widths'] ?? null,
            ]);

            foreach ($tabData['sections'] ?? [] as $secData) {
                $section = BlockSection::create([
                    'name' => $secData['name'],
                    'fieldable_type' => $ownerClass,
                    'fieldable_id' => $owner->id,
                    'block_id' => $blockId,
                    'block_tab_id' => $tab->id,
                    'order' => $secData['order'] ?? 0,
                    'column_index' => $secData['column_index'] ?? 0,
                ]);

                static::importFields($secData['fields'] ?? [], $owner, $tab, $section);
            }

            // Поля без секции (напрямую в табе)
            static::importFields($tabData['fields'] ?? [], $owner, $tab, null);
        }
    }

    /**
     * Рекурсивный импорт полей.
     *
     * @param array $fields
     * @param \Illuminate\Database\Eloquent\Model $owner
     * @param BlockTab $tab
     * @param BlockSection|null $section
     * @param int|null $parentId
     */
    public static function importFields(
        array $fields,
        Model $owner,
        BlockTab $tab,
        ?BlockSection $section,
        ?int $parentId = null
    ): void {
        $ownerClass = get_class($owner);
        $blockId = $owner instanceof Block ? $owner->id : null;

        foreach ($fields as $fData) {
            $field = BlockField::create([
                'name' => $fData['name'],
                'key' => $fData['key'],
                'type' => $fData['type'],
                'default_value' => $fData['default_value'] ?? null,
                'data' => $fData['data'] ?? null,
                'hint' => $fData['hint'] ?? null,
                'order' => $fData['order'] ?? 0,
                'parent_id' => $parentId,
                'fieldable_type' => $ownerClass,
                'fieldable_id' => $owner->id,
                'block_id' => $blockId,
                'block_tab_id' => $tab->id,
                'block_section_id' => $section?->id,
            ]);

            if (!empty($fData['children'])) {
                static::importFields($fData['children'], $owner, $tab, $section, $field->id);
            }
        }
    }

    /**
     * Импорт полей без табов (orphan fields).
     * Удаляет существующие orphan-поля перед импортом.
     */
    public static function importOrphanFields(self $block, array $fields, ?int $parentId = null): void
    {
        // Удаляем существующие orphan-поля только на верхнем уровне
        if ($parentId === null) {
            BlockField::where('block_id', $block->id)
                ->whereNull('block_tab_id')
                ->whereNull('parent_id')
                ->delete();
        }

        foreach ($fields as $fData) {
            $field = BlockField::create([
                'name' => $fData['name'],
                'key' => $fData['key'],
                'type' => $fData['type'],
                'default_value' => $fData['default_value'] ?? null,
                'data' => $fData['data'] ?? null,
                'hint' => $fData['hint'] ?? null,
                'order' => $fData['order'] ?? 0,
                'parent_id' => $parentId,
                'fieldable_type' => Block::class,
                'fieldable_id' => $block->id,
                'block_id' => $block->id,
                'block_tab_id' => null,
                'block_section_id' => null,
            ]);

            if (!empty($fData['children'])) {
                static::importOrphanFields($block, $fData['children'], $field->id);
            }
        }
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
