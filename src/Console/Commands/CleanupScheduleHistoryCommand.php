<?php

namespace Templite\Cms\Console\Commands;

use Illuminate\Console\Command;
use Templite\Cms\Models\ScheduleHistory;

class CleanupScheduleHistoryCommand extends Command
{
    protected $signature = 'cms:cleanup-schedule-history';
    protected $description = 'Очистка истории расписания старше 7 дней';

    public function handle(): int
    {
        $count = ScheduleHistory::where('ran_at', '<', now()->subDays(7))->count();
        ScheduleHistory::cleanup();

        $this->info("Удалено записей истории: {$count}");

        return self::SUCCESS;
    }
}
