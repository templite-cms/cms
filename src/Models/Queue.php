<?php

namespace Templite\Cms\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class Queue extends Model
{
    protected $table = 'cms_queues';

    protected $fillable = [
        'name',
        'priority',
        'tries',
        'timeout',
        'sleep',
        'process_via_schedule',
        'is_active',
    ];

    protected $casts = [
        'priority' => 'integer',
        'tries' => 'integer',
        'timeout' => 'integer',
        'sleep' => 'integer',
        'process_via_schedule' => 'boolean',
        'is_active' => 'boolean',
    ];

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeOrdered($query)
    {
        return $query->orderBy('priority')->orderBy('id');
    }

    public function scopeProcessViaSchedule($query)
    {
        return $query->where('process_via_schedule', true);
    }

    public static function activeNames(): array
    {
        return static::active()->ordered()->pluck('name')->toArray();
    }

    public static function clearScheduleCache(): void
    {
        Cache::forget('cms:schedule_config');
    }
}
