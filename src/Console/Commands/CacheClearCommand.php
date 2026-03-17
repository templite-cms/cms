<?php

namespace Templite\Cms\Console\Commands;

use Illuminate\Console\Command;
use Templite\Cms\Services\CacheManager;

/**
 * Artisan: cms:cache-clear
 *
 * Очистка всего кэша CMS: блоков, глобальных полей, SCSS.
 */
class CacheClearCommand extends Command
{
    protected $signature = 'cms:cache-clear
        {--blocks : Очистить только кэш блоков}
        {--global : Очистить только кэш глобальных полей}
        {--scss : Очистить только скомпилированные SCSS}';

    protected $description = 'Очистка кэша CMS';

    public function handle(CacheManager $cacheManager): int
    {
        $specific = $this->option('blocks')
            || $this->option('global')
            || $this->option('scss');

        if (!$specific) {
            $stats = $cacheManager->clearAll();
            $viewsCount = $stats['views']['files'] ?? 0;
            $this->info("Весь кэш CMS очищен. Блоков: {$stats['blocks']['cleared']}, глобальных: {$stats['global']['cleared']}, SCSS: {$stats['scss']['files']} файлов, compiled views: {$viewsCount}.");
            return self::SUCCESS;
        }

        if ($this->option('blocks')) {
            $stats = $cacheManager->clearBlocks();
            $this->info("Кэш блоков очищен. Записей: {$stats['cleared']}. Compiled views и OPcache сброшены.");
        }

        if ($this->option('global')) {
            $stats = $cacheManager->invalidateGlobalFields();
            $this->info("Кэш глобальных полей очищен. Записей: {$stats['cleared']}.");
        }

        if ($this->option('scss')) {
            $stats = $cacheManager->clearScss();
            $this->info("Скомпилированные SCSS очищены. Файлов: {$stats['files']}.");
        }

        return self::SUCCESS;
    }
}
