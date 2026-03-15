<?php

namespace Templite\Cms\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PageTypeAttribute extends Model
{
    protected $fillable = [
        'page_type_id',
        'name',
        'key',
        'type',
        'options',
        'filterable',
        'sortable',
        'required',
        'order',
    ];

    protected $casts = [
        'options' => 'json',
        'filterable' => 'boolean',
        'sortable' => 'boolean',
        'required' => 'boolean',
    ];

    public function pageType(): BelongsTo
    {
        return $this->belongsTo(PageType::class);
    }

    public function values(): HasMany
    {
        return $this->hasMany(PageAttributeValue::class, 'attribute_id');
    }

    public function scopeFilterable($query)
    {
        return $query->where('filterable', true);
    }

    public function scopeSortable($query)
    {
        return $query->where('sortable', true);
    }
}
