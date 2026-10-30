<?php

namespace Templite\Cms\Console\Commands;

use Illuminate\Console\Command;
use Templite\Cms\Models\CmsConfig;
use Templite\Cms\Models\ManagerLog;

class PruneManagerLogCommand extends Command
{
    protected $signature = 'cms:prune-manager-log';

    protected $description = 'Удаление записей журнала действий старше срока из настроек ядра';

    private const CHUNK = 1000;

    public function handle(): int
    {
        $days = (int) CmsConfig::getValue('manager_log_retention_days', 90);

        if ($days <= 0) {
            $this->info('Срок хранения журнала не ограничен');

            return self::SUCCESS;
        }

        $border = now()->subDays($days);
        $total = 0;

        // Пачками: с ИИ журнал растёт быстро, один DELETE на всю историю
        // надолго держит блокировку таблицы
        do {
            $ids = ManagerLog::where('created_at', '<', $border)->orderBy('id')->limit(self::CHUNK)->pluck('id');

            if ($ids->isNotEmpty()) {
                $total += ManagerLog::whereIn('id', $ids)->delete();
            }
        } while ($ids->count() === self::CHUNK);

        $this->info("Удалено записей журнала: {$total}");

        return self::SUCCESS;
    }
}
