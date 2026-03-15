<?php

namespace Templite\Cms\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PageAttributeValue extends Model
{
    protected $fillable = [
        'page_id',
        'attribute_id',
        'value',
    ];

    public function page(): BelongsTo
    {
        return $this->belongsTo(Page::class);
    }

    public function attribute(): BelongsTo
    {
        return $this->belongsTo(PageTypeAttribute::class, 'attribute_id');
    }
}
