<?php

namespace Templite\Cms\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PageTranslation extends Model
{
    protected $fillable = [
        'page_id',
        'lang',
        'title',
        'bread_title',
        'seo_data',
        'social_data',
    ];

    protected $casts = [
        'seo_data' => 'json',
        'social_data' => 'json',
    ];

    // --- Relationships ---

    public function page(): BelongsTo
    {
        return $this->belongsTo(Page::class);
    }
}
