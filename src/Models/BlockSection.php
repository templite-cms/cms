<?php

namespace Templite\Cms\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class BlockSection extends Model
{
    protected $fillable = [
        'name',
        'block_id',
        'fieldable_type',
        'fieldable_id',
        'block_tab_id',
        'order',
        'column_index',
    ];

    public function fieldable(): MorphTo
    {
        return $this->morphTo();
    }

    public function block(): BelongsTo
    {
        return $this->belongsTo(Block::class);
    }

    public function tab(): BelongsTo
    {
        return $this->belongsTo(BlockTab::class, 'block_tab_id');
    }

    public function fields(): HasMany
    {
        return $this->hasMany(BlockField::class)->orderBy('order');
    }
}
