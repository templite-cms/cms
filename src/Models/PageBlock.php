<?php

namespace Templite\Cms\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Templite\Cms\Enums\PageBlockStatus;

class PageBlock extends Model
{
    protected $fillable = [
        'page_id',
        'block_id',
        'data',
        'action_params',
        'status',
        'order',
        'cache_enabled',
        'cache_key',
        'preset_id',
    ];

    protected $casts = [
        'data' => 'json',
        'action_params' => 'json',
        'cache_enabled' => 'boolean',
        'status' => PageBlockStatus::class,
    ];

    /**
     * Resolved-данные (подставленные объекты вместо ID).
     * Заполняется BlockDataResolver.
     */
    public array $resolved_data = [];

    // --- Relationships ---

    public function page(): BelongsTo
    {
        return $this->belongsTo(Page::class);
    }

    public function block(): BelongsTo
    {
        return $this->belongsTo(Block::class);
    }

    public function preset(): BelongsTo
    {
        return $this->belongsTo(BlockPreset::class);
    }

    public function scopePublished($query)
    {
        return $query->where('status', PageBlockStatus::Published);
    }

    public function scopeVisible($query, bool $isManager = false)
    {
        if ($isManager) {
            return $query->whereIn('status', [PageBlockStatus::Published, PageBlockStatus::Draft]);
        }
        return $query->where('status', PageBlockStatus::Published);
    }

    // --- Methods ---

}
