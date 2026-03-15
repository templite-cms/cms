<?php

namespace Templite\Cms\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Templite\Cms\Concerns\HasExportable;
use Templite\Cms\Contracts\Exportable;
use Templite\Cms\Services\ImportExport\ImportContext;

class City extends Model implements Exportable
{
    use HasExportable;

    protected static ?Collection $cachedCities = null;
    protected $fillable = [
        'name',
        'name_genitive',
        'name_prepositional',
        'name_accusative',
        'slug',
        'region',
        'phone',
        'address',
        'email',
        'coordinates',
        'extra_data',
        'is_default',
        'is_active',
        'sort_order',
    ];

    protected $casts = [
        'coordinates' => 'json',
        'extra_data' => 'json',
        'is_default' => 'boolean',
        'is_active' => 'boolean',
        'sort_order' => 'integer',
    ];

    // --- Relationships ---

    public function cityPages(): HasMany
    {
        return $this->hasMany(CityPage::class);
    }

    public function materializedPages(): HasMany
    {
        return $this->hasMany(Page::class, 'city_id');
    }

    // --- Scopes ---

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeOrdered($query)
    {
        return $query->orderBy('sort_order')->orderBy('name');
    }

    // --- Helpers ---

    /**
     * Получить все активные города как Eloquent Collection (с кэшированием).
     *
     * Кэширует массивы моделей, при извлечении восстанавливает
     * полноценные Eloquent-модели с кастами и методами.
     */
    public static function getCachedAll(): Collection
    {
        if (static::$cachedCities !== null) {
            return static::$cachedCities;
        }

        $rows = cache()->remember('cities:active_models', null, function () {
            return static::active()->ordered()->get()->toArray();
        });

        static::$cachedCities = (new static)->newCollection(
            array_map(function (array $row) {
                return (new static)->forceFill($row)->syncOriginal();
            }, $rows)
        );

        return static::$cachedCities;
    }

    /**
     * Найти активный город по slug (из кэша).
     */
    public static function findBySlug(string $slug): ?self
    {
        return static::getCachedAll()->firstWhere('slug', $slug);
    }

    /**
     * Получить город по умолчанию (из кэша).
     */
    public static function getDefault(): ?self
    {
        return static::getCachedAll()->firstWhere('is_default', true)
            ?? static::getCachedAll()->first();
    }

    /**
     * Получить все активные города (кешируемый список).
     */
    public static function getCachedList(): array
    {
        return static::getCachedAll()->toArray();
    }

    /**
     * Очистить кеш городов.
     */
    public static function clearCache(): void
    {
        cache()->forget('cities:active_models');
        cache()->forget('cities:active_list');  // legacy
        cache()->forget('cities:slug_map');     // legacy
        static::$cachedCities = null;
    }

    /**
     * Получить карту slug → id для быстрого поиска.
     */
    public static function getSlugMap(): array
    {
        return static::getCachedAll()->pluck('id', 'slug')->toArray();
    }

    /**
     * Получить доступные плейсхолдеры для подстановки в тексты.
     */
    public function getPlaceholders(): array
    {
        return [
            '{city}' => $this->name,
            '{city_genitive}' => $this->name_genitive ?? $this->name,
            '{city_prepositional}' => $this->name_prepositional ?? $this->name,
            '{city_accusative}' => $this->name_accusative ?? $this->name,
            '{city_slug}' => $this->slug,
            '{phone}' => $this->phone ?? '',
            '{address}' => $this->address ?? '',
            '{email}' => $this->email ?? '',
        ];
    }

    // --- Exportable ---

    public function getExportType(): string { return 'city'; }
    public function getExportIdentifier(): string { return $this->slug; }
    public function getDependencies(): array { return []; }

    public function toExportArray(): array
    {
        return [
            'name' => $this->name,
            'slug' => $this->slug,
            'name_genitive' => $this->name_genitive,
            'name_prepositional' => $this->name_prepositional,
            'name_accusative' => $this->name_accusative,
            'region' => $this->region,
            'phone' => $this->phone,
            'address' => $this->address,
            'email' => $this->email,
            'coordinates' => $this->coordinates,
            'extra_data' => $this->extra_data,
            'is_default' => $this->is_default,
            'is_active' => $this->is_active,
            'sort_order' => $this->sort_order,
            'city_pages' => $this->cityPages->map(fn ($cp) => [
                'source_page_url' => $cp->sourcePage?->url,
                'is_materialized' => $cp->is_materialized,
                'title_override' => $cp->title_override,
                'bread_title_override' => $cp->bread_title_override,
                'seo_data_override' => $cp->seo_data_override,
                'social_data_override' => $cp->social_data_override,
                'template_data_override' => $cp->template_data_override,
                'status_override' => $cp->status_override,
                'block_overrides' => $cp->blockOverrides->map(fn ($bo) => [
                    'block_slug' => $bo->block?->slug,
                    'action' => $bo->action,
                    'data_override' => $bo->data_override,
                    'order_override' => $bo->order_override,
                ])->toArray(),
            ])->toArray(),
        ];
    }

    public static function fromImportArray(array $data, ImportContext $ctx): static
    {
        $cityData = collect($data)->except(['city_pages'])->toArray();
        $city = static::updateOrCreate(['slug' => $data['slug']], $cityData);

        // Re-create city pages
        $city->cityPages()->delete();
        foreach ($data['city_pages'] ?? [] as $cpData) {
            $sourcePageId = isset($cpData['source_page_url'])
                ? $ctx->resolveId('page', $cpData['source_page_url']) : null;
            if (!$sourcePageId) {
                continue;
            }

            $cp = $city->cityPages()->create([
                'source_page_id' => $sourcePageId,
                'is_materialized' => $cpData['is_materialized'] ?? false,
                'title_override' => $cpData['title_override'] ?? null,
                'bread_title_override' => $cpData['bread_title_override'] ?? null,
                'seo_data_override' => $cpData['seo_data_override'] ?? null,
                'social_data_override' => $cpData['social_data_override'] ?? null,
                'template_data_override' => $cpData['template_data_override'] ?? null,
                'status_override' => $cpData['status_override'] ?? null,
            ]);

            foreach ($cpData['block_overrides'] ?? [] as $boData) {
                $blockId = isset($boData['block_slug'])
                    ? $ctx->resolveId('block', $boData['block_slug']) : null;
                if (!$blockId) {
                    continue;
                }

                $cp->blockOverrides()->create([
                    'block_id' => $blockId,
                    'action' => $boData['action'],
                    'data_override' => $boData['data_override'] ?? null,
                    'order_override' => $boData['order_override'] ?? null,
                ]);
            }
        }

        return $city;
    }
}
