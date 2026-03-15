<?php

namespace Templite\Cms\Listeners;

use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Support\Facades\Cache;

class QueueStatsListener
{
    public function handle(JobProcessed $event): void
    {
        $hourKey = 'cms:queue_stats:processed:' . date('Y-m-d-H');
        $dayKey = 'cms:queue_stats:processed:' . date('Y-m-d');

        // Cache::add() only sets if key doesn't exist, ensuring TTL is set once
        Cache::add($hourKey, 0, 7200); // 2 hours
        Cache::add($dayKey, 0, 90000); // 25 hours

        Cache::increment($hourKey);
        Cache::increment($dayKey);
    }
}
