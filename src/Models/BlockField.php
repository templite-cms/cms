<?php

namespace Templite\Cms\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class BlockField extends Model
{
    protected $fillable = [
        'name',
        'block_id',
        'fieldable_type',
        'fieldable_id',
        'parent_id',
        'type',
        'key',
        'default_value',
        'data',
        'hint',
        'block_tab_id',
        'block_section_id',
        'order',
    ];

    protected $casts = [
        'data' => 'json',
    ];

    /**
     * Допустимые типы полей.
     */
    public const FIELD_TYPES = [
        'text', 'textfield', 'number', 'img', 'file', 'editor', 'html',
        'select', 'checkbox', 'radio', 'link', 'date', 'datetime',
        'array', 'category', 'product', 'product_option', 'color',
    ];

    /**
     * Зарезервированные ключи, которые нельзя использовать (BF-012).
     */
    public const RESERVED_KEYS = [
        'id', 'type', 'block', 'page', 'data', 'fields', 'global',
        'actionData', 'request', 'slot', 'attributes', 'errors',
    ];

    // --- Relationships ---

    public function fieldable(): MorphTo
    {
        return $this->morphTo();
    }

    public function block(): BelongsTo
    {
        return $this->belongsTo(Block::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('order');
    }

    public function tab(): BelongsTo
    {
        return $this->belongsTo(BlockTab::class, 'block_tab_id');
    }

    public function section(): BelongsTo
    {
        return $this->belongsTo(BlockSection::class, 'block_section_id');
    }

    // --- Methods ---

    /**
     * Является ли поле повторителем (array).
     */
    public function isRepeater(): bool
    {
        return $this->type === 'array';
    }

    /**
     * Является ли поле файловым (img, file).
     */
    public function isFileField(): bool
    {
        return in_array($this->type, ['img', 'file']);
    }

    /**
     * Является ли поле ссылкой на другую сущность.
     */
    public function isReferenceField(): bool
    {
        return in_array($this->type, ['img', 'file', 'category', 'product', 'product_option']);
    }

    /**
     * Получить подсказку для контент-менеджера.
     * Поддерживает и поле hint, и data.hint для обратной совместимости.
     */
    public function getHintTextAttribute(): ?string
    {
        return $this->hint ?? ($this->data['hint'] ?? null);
    }
}
