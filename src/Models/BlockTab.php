<?php

namespace Templite\Cms\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class BlockTab extends Model
{
    protected $fillable = [
        'name',
        'block_id',
        'fieldable_type',
        'fieldable_id',
        'order',
        'columns',
        'column_widths',
    ];

    protected $casts = [
        'column_widths' => 'array',
    ];

    public function fieldable(): MorphTo
    {
        return $this->morphTo();
    }

    public function block(): BelongsTo
    {
        return $this->belongsTo(Block::class);
    }

    public function sections(): HasMany
    {
        return $this->hasMany(BlockSection::class)->orderBy('order');
    }

    public function fields(): HasMany
    {
        return $this->hasMany(BlockField::class)->orderBy('order');
    }
}
