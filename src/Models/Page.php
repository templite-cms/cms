<?php

namespace Templite\Cms\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Templite\Cms\Concerns\HasExportable;
use Templite\Cms\Contracts\Exportable;
use Templite\Cms\Enums\PageBlockStatus;
use Templite\Cms\Services\ImportExport\ImportContext;
use Templite\Cms\Services\ImportExport\MediaFieldScanner;
use Templite\Cms\Traits\HasAttributes;
use Templite\Cms\Traits\HasFiles;

class Page extends Model implements Exportable
{
    use HasAttributes, HasFiles, HasExportable;

    protected $fillable = [
        'url',
        'alias',
        'parent_id',
        'type_id',
        'title',
        'bread_title',
        'seo_data',
        'social_data',
        'template_page_id',
        'template_data',
        'status',
        'city_scope',
        'city_id',
        'display_tree',
        'views',
        'img',
        'screen',
        'order',
        'publish_at',
        'unpublish_at',
    ];

    protected $casts = [
        'seo_data' => 'json',
        'social_data' => 'json',
        'template_data' => 'json',
        'status' => 'integer',
        'display_tree' => 'boolean',
        'views' => 'integer',
        'order' => 'integer',
        'publish_at' => 'datetime',
        'unpublish_at' => 'datetime',
    ];

    // --- City Scope Constants ---
    const CITY_SCOPE_GLOBAL = 'global';
    const CITY_SCOPE_CITY_SOURCE = 'city_source';
    const CITY_SCOPE_MATERIALIZED = 'materialized';

    // --- Relationships ---

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('order');
    }

    public function type(): BelongsTo
    {
        return $this->belongsTo(PageType::class, 'type_id');
    }

    /**
     * Алиас для type() — используется в контроллерах и ресурсах.
     */
    public function pageType(): BelongsTo
    {
        return $this->belongsTo(PageType::class, 'type_id');
    }

    public function templatePage(): BelongsTo
    {
        return $this->belongsTo(TemplatePage::class);
    }

    public function pageBlocks(): HasMany
    {
        return $this->hasMany(PageBlock::class)->orderBy('order');
    }

    public function attributeValues(): HasMany
    {
        return $this->hasMany(PageAttributeValue::class);
    }

    public function relatedPages(): BelongsToMany
    {
        return $this->belongsToMany(self::class, 'page_to_page', 'page_id', 'related_page_id');
    }

    public function asset(): HasOne
    {
        return $this->hasOne(PageAsset::class);
    }

    public function city(): BelongsTo
    {
        return $this->belongsTo(City::class);
    }

    public function cityPages(): HasMany
    {
        return $this->hasMany(CityPage::class, 'source_page_id');
    }

    public function translations(): HasMany
    {
        return $this->hasMany(PageTranslation::class);
    }

    public function translation(string $lang): ?PageTranslation
    {
        return $this->translations()->where('lang', $lang)->first();
    }

    // --- Scopes ---

    public function scopePublished($query)
    {
        return $query->where('status', 1)
            ->where(function ($q) {
                $q->whereNull('publish_at')
                  ->orWhere('publish_at', '<=', now());
            })
            ->where(function ($q) {
                $q->whereNull('unpublish_at')
                  ->orWhere('unpublish_at', '>', now());
            });
    }

    public function scopeDraft($query)
    {
        return $query->where('status', 0);
    }

    public function scopeByType($query, int $typeId)
    {
        return $query->where('type_id', $typeId);
    }

    public function scopeRoot($query)
    {
        return $query->whereNull('parent_id');
    }

    public function scopeVisible($query)
    {
        return $query->where('display_tree', true);
    }

    public function scopeCitySource($query)
    {
        return $query->where('city_scope', self::CITY_SCOPE_CITY_SOURCE);
    }

    public function scopeGlobalScope($query)
    {
        return $query->where('city_scope', self::CITY_SCOPE_GLOBAL);
    }

    public function scopeMaterialized($query)
    {
        return $query->where('city_scope', self::CITY_SCOPE_MATERIALIZED);
    }

    // --- Accessors ---

    public function getIsPublishedAttribute(): bool
    {
        if ($this->status !== 1) {
            return false;
        }
        if ($this->publish_at && $this->publish_at->isFuture()) {
            return false;
        }
        if ($this->unpublish_at && $this->unpublish_at->isPast()) {
            return false;
        }
        return true;
    }

    public function getBreadTitleDisplayAttribute(): string
    {
        return $this->bread_title ?? $this->title;
    }

    /**
     * Получить полный URL страницы.
     */
    public function getFullUrlAttribute(): string
    {
        return '/' . ltrim($this->url, '/');
    }

    public function isCitySource(): bool
    {
        return $this->city_scope === self::CITY_SCOPE_CITY_SOURCE;
    }

    public function isGlobalScope(): bool
    {
        return $this->city_scope === self::CITY_SCOPE_GLOBAL;
    }

    public function isMaterialized(): bool
    {
        return $this->city_scope === self::CITY_SCOPE_MATERIALIZED;
    }

    // --- Exportable ---

    public function getExportType(): string { return 'page'; }
    public function getExportIdentifier(): string { return $this->url; }

    public function getDependencies(): array
    {
        $deps = [];
        if ($this->parent) {
            $deps[] = $this->parent;
        }
        if ($this->pageType) {
            $deps[] = $this->pageType;
        }
        if ($this->templatePage) {
            $deps[] = $this->templatePage;
        }
        foreach ($this->pageBlocks as $pb) {
            if ($pb->block) {
                $deps[] = $pb->block;
            }
            if ($pb->preset) {
                $deps[] = $pb->preset;
            }
        }

        // Auto-resolve media files referenced in page block data
        $fileIds = $this->collectMediaFileIds();
        if (!empty($fileIds)) {
            $files = File::whereIn('id', $fileIds)->get();
            foreach ($files as $file) {
                $deps[] = $file;
            }
        }

        return $deps;
    }

    public function toExportArray(): array
    {
        // Collect all file IDs from all page_blocks data + translations,
        // then batch-load for path replacement
        $allFileIds = $this->collectMediaFileIds();
        $fileMap = !empty($allFileIds)
            ? File::whereIn('id', $allFileIds)->get()->keyBy('id')
            : collect();

        return [
            'url' => $this->url,
            'alias' => $this->alias,
            'parent_url' => $this->parent?->url,
            'type_slug' => $this->pageType?->slug,
            'template_slug' => $this->templatePage?->slug,
            'title' => $this->title,
            'bread_title' => $this->bread_title,
            'seo_data' => $this->seo_data,
            'social_data' => $this->social_data,
            'template_data' => $this->template_data,
            'status' => $this->status,
            'display_tree' => $this->display_tree,
            'order' => $this->order,
            'publish_at' => $this->publish_at?->toIso8601String(),
            'unpublish_at' => $this->unpublish_at?->toIso8601String(),
            'page_blocks' => $this->pageBlocks->sortBy('order')->map(function ($pb) use ($fileMap) {
                $data = $pb->data ?? [];
                $translations = $pb->translations;

                if ($pb->block && !empty($data)) {
                    $fields = $this->getBlockFields($pb->block_id, $pb->block);
                    $data = MediaFieldScanner::replaceIdsWithPaths($fields, $data, $fileMap);

                    $translations = $translations->map(function ($t) use ($fields, $fileMap) {
                        $tData = $t->data ?? [];
                        if (!empty($tData)) {
                            $tData = MediaFieldScanner::replaceIdsWithPaths($fields, $tData, $fileMap);
                        }
                        return [
                            'lang' => $t->lang,
                            'data' => $tData,
                        ];
                    });
                } else {
                    $translations = $translations->map(fn ($t) => [
                        'lang' => $t->lang,
                        'data' => $t->data,
                    ]);
                }

                return [
                    'block_slug' => $pb->block?->slug,
                    'preset_slug' => $pb->preset?->slug,
                    'data' => $data,
                    'action_params' => $pb->action_params,
                    'status' => $pb->status instanceof PageBlockStatus
                        ? $pb->status->value
                        : $pb->status,
                    'order' => $pb->order,
                    'field_overrides' => $pb->field_overrides,
                    'translations' => $translations->toArray(),
                ];
            })->values()->toArray(),
            'attribute_values' => $this->attributeValues->map(fn ($av) => [
                'attribute_key' => $av->attribute?->key,
                'value' => $av->value,
            ])->filter(fn ($av) => $av['attribute_key'] !== null)->values()->toArray(),
            'translations' => $this->translations->map(fn ($t) => [
                'lang' => $t->lang,
                'title' => $t->title,
                'bread_title' => $t->bread_title,
                'seo_data' => $t->seo_data,
                'social_data' => $t->social_data,
            ])->toArray(),
        ];
    }

    public static function fromImportArray(array $data, ImportContext $ctx): static
    {
        $parentId = isset($data['parent_url'])
            ? $ctx->resolveId('page', $data['parent_url']) : null;
        $typeId = isset($data['type_slug'])
            ? $ctx->resolveId('page_type', $data['type_slug']) : null;
        $templateId = isset($data['template_slug'])
            ? $ctx->resolveId('template', $data['template_slug']) : null;

        $page = static::updateOrCreate(
            ['url' => $data['url']],
            [
                'alias' => $data['alias'] ?? null,
                'parent_id' => $parentId,
                'type_id' => $typeId,
                'template_page_id' => $templateId,
                'title' => $data['title'] ?? '',
                'bread_title' => $data['bread_title'] ?? null,
                'seo_data' => $data['seo_data'] ?? null,
                'social_data' => $data['social_data'] ?? null,
                'template_data' => $data['template_data'] ?? null,
                'status' => $data['status'] ?? 0,
                'display_tree' => $data['display_tree'] ?? true,
                'order' => $data['order'] ?? 0,
                'publish_at' => $data['publish_at'] ?? null,
                'unpublish_at' => $data['unpublish_at'] ?? null,
            ]
        );

        // Re-create page blocks
        $page->pageBlocks()->delete();
        foreach ($data['page_blocks'] ?? [] as $pbData) {
            $block = $ctx->resolve('block', $pbData['block_slug'] ?? '');
            if (!$block) {
                continue;
            }

            $presetId = isset($pbData['preset_slug'])
                ? $ctx->resolveId('preset', $pbData['preset_slug']) : null;

            // Remap file paths back to IDs in block data
            $blockData = $pbData['data'] ?? null;
            $fields = $block->fields()->with('children')->get();
            if (is_array($blockData) && $fields->isNotEmpty()) {
                $blockData = MediaFieldScanner::replacePathsWithIds($fields, $blockData, $ctx);
            }

            $pb = $page->pageBlocks()->create([
                'block_id' => $block->id,
                'preset_id' => $presetId,
                'data' => $blockData,
                'action_params' => $pbData['action_params'] ?? null,
                'status' => $pbData['status'] ?? PageBlockStatus::Published->value,
                'order' => $pbData['order'] ?? 0,
                'field_overrides' => $pbData['field_overrides'] ?? null,
            ]);

            // Create page block translations with remapped file paths
            foreach ($pbData['translations'] ?? [] as $tData) {
                $tDataToSave = $tData;
                if (is_array($tData['data'] ?? null) && $fields->isNotEmpty()) {
                    $tDataToSave['data'] = MediaFieldScanner::replacePathsWithIds($fields, $tData['data'], $ctx);
                }
                $pb->translations()->create($tDataToSave);
            }
        }

        // Re-create attribute values
        $page->attributeValues()->delete();
        if ($typeId) {
            $pageType = $ctx->resolve('page_type', $data['type_slug']);
            if ($pageType) {
                foreach ($data['attribute_values'] ?? [] as $avData) {
                    $attr = $pageType->attributes->firstWhere('key', $avData['attribute_key']);
                    if ($attr) {
                        $page->attributeValues()->create([
                            'attribute_id' => $attr->id,
                            'value' => $avData['value'],
                        ]);
                    }
                }
            }
        }

        // Create page translations
        $page->translations()->delete();
        foreach ($data['translations'] ?? [] as $tData) {
            $page->translations()->create($tData);
        }

        return $page;
    }

    /**
     * Получить медиафайлы для включения в ZIP-архив экспорта.
     *
     * Сканирует все page_blocks data и translations на наличие файлов,
     * загружает File-модели и собирает все физические пути
     * (оригинал + ресайзы + webp-варианты).
     *
     * @return string[]
     */
    public function getExportMediaFiles(): array
    {
        $fileIds = $this->collectMediaFileIds();
        if (empty($fileIds)) {
            return [];
        }

        $files = File::whereIn('id', $fileIds)->get();
        $paths = [];
        foreach ($files as $file) {
            $paths = array_merge($paths, $file->getExportMediaFiles());
        }

        return array_values(array_unique($paths));
    }

    /** @var array<int, \Illuminate\Support\Collection>|null Кэш полей блоков (block_id => fields) */
    protected ?array $cachedBlockFields = null;

    /** @var int[]|null Кэш собранных file ID */
    protected ?array $cachedMediaFileIds = null;

    /**
     * Получить поля блока с кэшированием для текущего запроса.
     *
     * @param int $blockId
     * @param Block $block
     * @return \Illuminate\Support\Collection
     */
    protected function getBlockFields(int $blockId, Block $block): \Illuminate\Support\Collection
    {
        if ($this->cachedBlockFields === null) {
            $this->cachedBlockFields = [];
        }
        if (!isset($this->cachedBlockFields[$blockId])) {
            $this->cachedBlockFields[$blockId] = $block->fields()->with('children')->get();
        }
        return $this->cachedBlockFields[$blockId];
    }

    /**
     * Собрать все file ID из данных page_blocks (data + translations).
     *
     * Используется в getDependencies(), toExportArray() и getExportMediaFiles()
     * для единообразного сканирования медиафайлов.
     * Результат кэшируется на время жизни модели.
     *
     * @return int[]
     */
    protected function collectMediaFileIds(): array
    {
        if ($this->cachedMediaFileIds !== null) {
            return $this->cachedMediaFileIds;
        }

        $allIds = [];

        foreach ($this->pageBlocks as $pb) {
            if (!$pb->block) {
                continue;
            }

            $fields = $this->getBlockFields($pb->block_id, $pb->block);

            // Scan main data
            if (is_array($pb->data) && !empty($pb->data)) {
                $ids = MediaFieldScanner::extractFileIds($fields, $pb->data);
                $allIds = array_merge($allIds, $ids);
            }

            // Scan translations data
            foreach ($pb->translations as $t) {
                if (is_array($t->data) && !empty($t->data)) {
                    $ids = MediaFieldScanner::extractFileIds($fields, $t->data);
                    $allIds = array_merge($allIds, $ids);
                }
            }
        }

        return $this->cachedMediaFileIds = array_values(array_unique($allIds));
    }
}
