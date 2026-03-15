<?php

namespace Templite\Cms\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CityPageBlock extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'city_page_id',
        'page_block_id',
        'block_id',
        'action',
        'data_override',
        'order_override',
    ];

    protected $casts = [
        'data_override' => 'json',
        'order_override' => 'integer',
    ];

    // --- Relationships ---

    public function cityPage(): BelongsTo
    {
        return $this->belongsTo(CityPage::class);
    }

    public function pageBlock(): BelongsTo
    {
        return $this->belongsTo(PageBlock::class);
    }

    public function block(): BelongsTo
    {
        return $this->belongsTo(Block::class);
    }

    // --- Helpers ---

    public function isOverride(): bool
    {
        return $this->action === 'override';
    }

    public function isHidden(): bool
    {
        return $this->action === 'hide';
    }

    public function isAdded(): bool
    {
        return $this->action === 'add';
    }
}
