<?php

namespace Templite\Cms\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PageBlockTranslation extends Model
{
    protected $fillable = [
        'page_block_id',
        'lang',
        'data',
    ];

    protected $casts = [
        'data' => 'json',
    ];

    // --- Relationships ---

    public function pageBlock(): BelongsTo
    {
        return $this->belongsTo(PageBlock::class);
    }
}
