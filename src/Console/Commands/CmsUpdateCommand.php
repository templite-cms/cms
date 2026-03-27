<?php

namespace Templite\Cms\Console\Commands;

use Illuminate\Console\Command;

/**
 * Artisan: cms:update
 *
 * Обновление CMS после composer update: публикация ассетов и миграции.
 */
class CmsUpdateCommand extends Command
{
    protected $signature = 'cms:update
        {--skip-migrate : Не запускать миграции}';

    protected $description = 'Обновление Templite CMS после composer update';

    public function handle(): int
    {
        $this->info('');
        $this->info('  ╔══════════════════════════════════╗');
        $this->info('  ║   Templite CMS -- Обновление     ║');
        $this->info('  ╚══════════════════════════════════╝');
        $this->info('');

        // 1. Публикация build (dist)
        $this->task('Публикация build', function () {
            $this->callSilent('vendor:publish', [
                '--tag' => 'cms-build',
                '--force' => true,
            ]);
            return true;
        });

        // 2. Публикация ассетов
        $this->task('Публикация ассетов', function () {
            $this->callSilent('vendor:publish', [
                '--tag' => 'cms-assets',
                '--force' => true,
            ]);
            return true;
        });

        // 3. Миграции
        if (!$this->option('skip-migrate')) {
            $this->task('Запуск миграций', function () {
                $code = $this->callSilent('migrate', ['--force' => true]);
                return $code === 0;
            });
        }

        $this->info('');
        $this->info('  Templite CMS успешно обновлена!');
        $this->info('');

        return self::SUCCESS;
    }

    protected function task(string $title, callable $callback): void
    {
        $this->output->write("  {$title}...");

        try {
            $result = $callback();
            $this->output->writeln($result ? ' <info>OK</info>' : ' <error>FAILED</error>');
        } catch (\Throwable $e) {
            $this->output->writeln(' <error>ERROR: ' . $e->getMessage() . '</error>');
        }
    }
}
