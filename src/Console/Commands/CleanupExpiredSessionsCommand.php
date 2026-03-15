<?php

namespace Templite\Cms\Console\Commands;

use Illuminate\Console\Command;
use Templite\Cms\Models\ManagerSession;

class CleanupExpiredSessionsCommand extends Command
{
    protected $signature = 'cms:cleanup-expired-sessions';
    protected $description = 'Очистка истёкших сессий менеджеров';

    public function handle(): int
    {
        $count = ManagerSession::expired()->count();
        ManagerSession::expired()->delete();

        $this->info("Удалено истёкших сессий: {$count}");

        return self::SUCCESS;
    }
}
