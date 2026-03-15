<?php

namespace Templite\Cms\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class GlobalFieldValue extends Model
{
    protected $fillable = [
        'global_field_id',
        'parent_id',
        'value',
        'order',
    ];

    // --- Relationships ---

    public function field(): BelongsTo
    {
        return $this->belongsTo(GlobalField::class, 'global_field_id');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('order');
    }

    /**
     * Рекурсивная загрузка всех потомков (дети, внуки и т.д.).
     */
    public function allDescendants(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('order')->with('allDescendants');
    }

    public function translations(): HasMany
    {
        return $this->hasMany(GlobalFieldValueTranslation::class);
    }
}
