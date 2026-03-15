<?php

namespace Templite\Cms\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Finder\Finder;

/**
 * Artisan: cms:cleanup-exports
 *
 * Удаляет устаревшие ZIP-файлы экспорта и импорта из storage/app/exports/ и storage/app/imports/.
 * По умолчанию удаляет файлы старше 7 дней.
 */
class CleanupExportsCommand extends Command
{
    protected $signature = 'cms:cleanup-exports
        {--days=7 : Удалять файлы старше указанного количества дней}
        {--exports-only : Очистить только директорию exports}
        {--imports-only : Очистить только директорию imports}
        {--dry-run : Показать что будет удалено, без фактического удаления}';

    protected $description = 'Очистка устаревших ZIP-файлов экспорта и импорта';

    /**
     * Директории для очистки (относительно storage/app/).
     *
     * @var string[]
     */
    protected array $directories = [
        'exports',
        'imports',
    ];

    public function handle(): int
    {
        $days = max(1, (int) $this->option('days'));
        $dryRun = (bool) $this->option('dry-run');
        $exportsOnly = (bool) $this->option('exports-only');
        $importsOnly = (bool) $this->option('imports-only');

        $dirs = $this->directories;

        if ($exportsOnly) {
            $dirs = ['exports'];
        } elseif ($importsOnly) {
            $dirs = ['imports'];
        }

        $totalDeleted = 0;
        $totalSize = 0;

        foreach ($dirs as $dir) {
            [$deleted, $size] = $this->cleanDirectory($dir, $days, $dryRun);
            $totalDeleted += $deleted;
            $totalSize += $size;
        }

        if ($dryRun) {
            $this->info("[dry-run] Будет удалено файлов: {$totalDeleted} ({$this->formatBytes($totalSize)})");
        } else {
            $this->info("Удалено файлов: {$totalDeleted} ({$this->formatBytes($totalSize)})");
        }

        return self::SUCCESS;
    }

    /**
     * Очистить одну директорию от файлов старше $days дней.
     *
     * @param string $dir Имя директории относительно storage/app/
     * @param int $days Возраст файлов в днях
     * @param bool $dryRun Режим предпросмотра
     * @return array{0: int, 1: int} [количество удалённых, общий размер в байтах]
     */
    protected function cleanDirectory(string $dir, int $days, bool $dryRun): array
    {
        $path = storage_path("app/{$dir}");

        if (!is_dir($path)) {
            $this->line("Директория {$dir}/ не существует, пропуск.");
            return [0, 0];
        }

        $threshold = now()->subDays($days)->getTimestamp();
        $deleted = 0;
        $size = 0;

        $files = glob($path . '/*.zip');

        if (empty($files)) {
            $this->line("Директория {$dir}/ пуста.");
            return [0, 0];
        }

        foreach ($files as $file) {
            if (!is_file($file)) {
                continue;
            }

            $mtime = filemtime($file);

            if ($mtime === false || $mtime >= $threshold) {
                continue;
            }

            $fileSize = filesize($file) ?: 0;

            if ($dryRun) {
                $this->line("  [dry-run] {$dir}/" . basename($file)
                    . ' (' . $this->formatBytes($fileSize)
                    . ', изменён ' . date('Y-m-d H:i:s', $mtime) . ')');
            } else {
                if (@unlink($file)) {
                    $this->line("  Удалён: {$dir}/" . basename($file));
                } else {
                    $this->warn("  Не удалось удалить: {$dir}/" . basename($file));
                    continue;
                }
            }

            $deleted++;
            $size += $fileSize;
        }

        return [$deleted, $size];
    }

    /**
     * Форматировать размер файла в человекочитаемый вид.
     */
    protected function formatBytes(int $bytes): string
    {
        if ($bytes === 0) {
            return '0 B';
        }

        $units = ['B', 'KB', 'MB', 'GB'];
        $i = 0;

        while ($bytes >= 1024 && $i < count($units) - 1) {
            $bytes /= 1024;
            $i++;
        }

        return round($bytes, 2) . ' ' . $units[$i];
    }
}
