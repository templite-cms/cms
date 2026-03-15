<?php

namespace Templite\Cms\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class GlobalField extends Model
{
    protected $fillable = [
        'name',
        'parent_id',
        'type',
        'key',
        'default_value',
        'data',
        'global_field_page_id',
        'global_field_section_id',
        'order',
    ];

    protected $casts = [
        'data' => 'json',
    ];

    // --- Relationships ---

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('order');
    }

    /**
     * Рекурсивная загрузка всех вложенных полей (дети, внуки и т.д.).
     */
    public function allChildren(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('order')->with('allChildren');
    }

    public function fieldPage(): BelongsTo
    {
        return $this->belongsTo(GlobalFieldPage::class, 'global_field_page_id');
    }

    public function section(): BelongsTo
    {
        return $this->belongsTo(GlobalFieldSection::class, 'global_field_section_id');
    }

    public function values(): HasMany
    {
        return $this->hasMany(GlobalFieldValue::class)->orderBy('order');
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
     * Получить значение поля (первое значение или default_value).
     */
    public function getValue(): mixed
    {
        $value = $this->values()->first();

        return $value ? $value->value : $this->default_value;
    }
}
