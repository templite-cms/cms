<?php

namespace Templite\Cms\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class ScheduledTask extends Model
{
    protected $table = 'cms_scheduled_tasks';

    protected $fillable = [
        'command',
        'arguments',
        'expression',
        'description',
        'is_system',
        'is_active',
        'without_overlapping',
    ];

    protected $casts = [
        'is_system' => 'boolean',
        'is_active' => 'boolean',
        'without_overlapping' => 'boolean',
    ];

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeSystem($query)
    {
        return $query->where('is_system', true);
    }

    public function scopeCustom($query)
    {
        return $query->where('is_system', false);
    }

    public static function clearScheduleCache(): void
    {
        Cache::forget('cms:schedule_config');
    }
}
