<?php

namespace Templite\Cms\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PageBlockData extends Model
{
    protected $table = 'page_block_data';

    protected $fillable = [
        'page_block_id',
        'block_id',
        'data',
        'action_params',
        'user_id',
        'change_type',
    ];

    protected $casts = [
        'data' => 'json',
        'action_params' => 'json',
    ];

    public function pageBlock(): BelongsTo
    {
        return $this->belongsTo(PageBlock::class);
    }

    public function block(): BelongsTo
    {
        return $this->belongsTo(Block::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(Manager::class, 'user_id');
    }
}
