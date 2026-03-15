<?php

namespace Templite\Cms\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
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
        'page_block_data_id',
        'preset_id',
        'field_overrides',
    ];

    protected $casts = [
        'data' => 'json',
        'action_params' => 'json',
        'cache_enabled' => 'boolean',
        'field_overrides' => 'json',
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

    public function activeVersion(): BelongsTo
    {
        return $this->belongsTo(PageBlockData::class, 'page_block_data_id');
    }

    public function versions(): HasMany
    {
        return $this->hasMany(PageBlockData::class)->orderByDesc('created_at');
    }

    public function translations(): HasMany
    {
        return $this->hasMany(PageBlockTranslation::class);
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

    /**
     * Создать новую версию данных блока и синхронизировать с page_blocks.
     */
    public function createVersion(array $data, ?array $actionParams = null, ?int $userId = null, string $changeType = 'native'): PageBlockData
    {
        $version = PageBlockData::create([
            'page_block_id' => $this->id,
            'block_id' => $this->block_id,
            'data' => $data,
            'action_params' => $actionParams,
            'user_id' => $userId,
            'change_type' => $changeType,
        ]);

        $this->update([
            'page_block_data_id' => $version->id,
            'data' => $data,
            'action_params' => $actionParams,
        ]);

        return $version;
    }

    /**
     * Получить ключ кэша для блока.
     */
    public function getCacheKeyString(): string
    {
        $parts = ['block', $this->page_id, $this->block_id];
        if ($this->cache_key) {
            $parts[] = $this->cache_key;
        }
        return implode(':', $parts);
    }
}
