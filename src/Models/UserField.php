<?php

namespace Templite\Cms\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class UserField extends Model
{
    protected $table = 'cms_user_fields';

    protected $fillable = [
        'user_type_id',
        'parent_id',
        'name',
        'key',
        'type',
        'default_value',
        'data',
        'hint',
        'tab',
        'order',
    ];

    protected $casts = [
        'data' => 'json',
    ];

    /**
     * Допустимые типы полей (без shop-типов: category, product, product_option).
     */
    public const FIELD_TYPES = [
        'text', 'textfield', 'number', 'img', 'file', 'editor', 'tiptap', 'html',
        'select', 'checkbox', 'radio', 'link', 'date', 'datetime',
        'array', 'color', 'page', 'user',
    ];

    /**
     * Зарезервированные ключи, которые нельзя использовать.
     */
    public const RESERVED_KEYS = [
        'id', 'name', 'email', 'password', 'avatar', 'avatar_id',
        'type', 'user_type_id', 'settings', 'is_active', 'data',
    ];

    // --- Relationships ---

    public function userType(): BelongsTo
    {
        return $this->belongsTo(UserType::class, 'user_type_id');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('order');
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
}
