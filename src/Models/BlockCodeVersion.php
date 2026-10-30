<?php

namespace Templite\Cms\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BlockCodeVersion extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['block_id', 'template', 'style', 'script', 'user_id', 'source'];

    public function block(): BelongsTo
    {
        return $this->belongsTo(Block::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(Manager::class, 'user_id');
    }
}
