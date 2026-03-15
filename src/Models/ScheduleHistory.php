<?php

namespace Templite\Cms\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;

class ScheduleHistory extends Model
{
    public $timestamps = false;

    protected $table = 'cms_schedule_history';

    protected $fillable = [
        'command',
        'status',
        'output',
        'duration_ms',
        'error',
        'ran_at',
    ];

    protected $casts = [
        'ran_at' => 'datetime',
        'duration_ms' => 'integer',
    ];

    public function scopeRecent(Builder $query): Builder
    {
        return $query->where('ran_at', '>=', now()->subDays(7));
    }

    public function scopeForCommand(Builder $query, string $command): Builder
    {
        return $query->where('command', $command);
    }

    public static function cleanup(): int
    {
        return static::where('ran_at', '<', now()->subDays(7))->delete();
    }
}
