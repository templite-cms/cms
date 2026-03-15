<?php

namespace Templite\Cms\Traits;

use Templite\Cms\Models\File;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Трейт для моделей, имеющих связь с файлами (изображениями).
 */
trait HasFiles
{
    /**
     * Связь с изображением (img).
     */
    public function image(): BelongsTo
    {
        return $this->belongsTo(File::class, 'img');
    }

    /**
     * Связь со скриншотом (screen).
     */
    public function screenshot(): BelongsTo
    {
        return $this->belongsTo(File::class, 'screen');
    }
}
