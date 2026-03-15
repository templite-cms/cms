<?php

namespace Templite\Cms\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GlobalFieldValueTranslation extends Model
{
    protected $fillable = [
        'global_field_value_id',
        'lang',
        'value',
    ];

    // --- Relationships ---

    public function globalFieldValue(): BelongsTo
    {
        return $this->belongsTo(GlobalFieldValue::class);
    }
}
