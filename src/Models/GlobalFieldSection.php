<?php

namespace Templite\Cms\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class GlobalFieldSection extends Model
{
    protected $fillable = [
        'name',
        'global_field_page_id',
        'order',
        'column_index',
    ];

    // --- Relationships ---

    public function page(): BelongsTo
    {
        return $this->belongsTo(GlobalFieldPage::class, 'global_field_page_id');
    }

    public function fields(): HasMany
    {
        return $this->hasMany(GlobalField::class)->orderBy('order');
    }
}
