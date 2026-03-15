<?php

namespace Templite\Cms\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CityPage extends Model
{
    protected $fillable = [
        'city_id',
        'source_page_id',
        'is_materialized',
        'materialized_page_id',
        'title_override',
        'bread_title_override',
        'seo_data_override',
        'social_data_override',
        'template_data_override',
        'status_override',
    ];

    protected $casts = [
        'is_materialized' => 'boolean',
        'seo_data_override' => 'json',
        'social_data_override' => 'json',
        'template_data_override' => 'json',
        'status_override' => 'integer',
    ];

    // --- Relationships ---

    public function city(): BelongsTo
    {
        return $this->belongsTo(City::class);
    }

    public function sourcePage(): BelongsTo
    {
        return $this->belongsTo(Page::class, 'source_page_id');
    }

    public function materializedPage(): BelongsTo
    {
        return $this->belongsTo(Page::class, 'materialized_page_id');
    }

    public function blockOverrides(): HasMany
    {
        return $this->hasMany(CityPageBlock::class);
    }

    // --- Helpers ---

    /**
     * Проверяет, есть ли какие-либо оверрайды.
     */
    public function hasOverrides(): bool
    {
        return $this->title_override !== null
            || $this->bread_title_override !== null
            || $this->seo_data_override !== null
            || $this->social_data_override !== null
            || $this->template_data_override !== null
            || $this->status_override !== null
            || $this->blockOverrides()->exists();
    }

    /**
     * Применить оверрайды к данным страницы-источника.
     */
    public function applyOverrides(Page $sourcePage): array
    {
        $data = [
            'title' => $this->title_override ?? $sourcePage->title,
            'bread_title' => $this->bread_title_override ?? $sourcePage->bread_title,
            'seo_data' => $this->seo_data_override
                ? array_merge($sourcePage->seo_data ?? [], $this->seo_data_override)
                : $sourcePage->seo_data,
            'social_data' => $this->social_data_override
                ? array_merge($sourcePage->social_data ?? [], $this->social_data_override)
                : $sourcePage->social_data,
            'template_data' => $this->template_data_override
                ? array_merge($sourcePage->template_data ?? [], $this->template_data_override)
                : $sourcePage->template_data,
            'status' => $this->status_override ?? $sourcePage->status,
        ];

        return $data;
    }
}
