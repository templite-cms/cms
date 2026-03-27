<?php

namespace Templite\Cms\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BlockPresetData extends Model
{
    protected $table = 'block_preset_data';

    protected $fillable = [
        'preset_id',
        'block_id',
        'data',
        'user_id',
        'change_type',
    ];

    protected $casts = [
        'data' => 'json',
    ];

    public function preset(): BelongsTo
    {
        return $this->belongsTo(BlockPreset::class, 'preset_id');
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
