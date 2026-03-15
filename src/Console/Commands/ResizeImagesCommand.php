<?php

namespace Templite\Cms\Console\Commands;

use Illuminate\Console\Command;
use Templite\Cms\Jobs\ProcessImage;
use Templite\Cms\Models\File;

/**
 * Artisan: cms:resize-images
 *
 * Пересоздание ресайзов для всех (или конкретных) изображений.
 */
class ResizeImagesCommand extends Command
{
    protected $signature = 'cms:resize-images
        {--all : Пересоздать ресайзы для всех изображений}
        {--id=* : ID конкретных файлов для обработки}
        {--sync : Обработать синхронно (без очереди)}';

    protected $description = 'Пересоздать ресайзы изображений CMS';

    public function handle(): int
    {
        $query = File::where('type', 'image');

        if ($ids = $this->option('id')) {
            $query->whereIn('id', $ids);
        } elseif (!$this->option('all')) {
            // По умолчанию — только файлы без ресайзов
            $query->where(function ($q) {
                $q->whereNull('sizes')->orWhere('sizes', '[]')->orWhere('sizes', '{}');
            });
        }

        $files = $query->get();
        $count = $files->count();

        if ($count === 0) {
            $this->info('Нет изображений для обработки.');
            return self::SUCCESS;
        }

        $this->info("Найдено изображений: {$count}");

        $bar = $this->output->createProgressBar($count);
        $bar->start();

        $processed = 0;
        $errors = 0;

        foreach ($files as $file) {
            try {
                if ($this->option('sync')) {
                    // Синхронная обработка
                    $job = new ProcessImage($file->id);
                    $job->handle(app(\Templite\Cms\Services\ImageProcessor::class));
                } else {
                    // В очередь
                    ProcessImage::dispatch($file->id);
                }
                $processed++;
            } catch (\Throwable $e) {
                $errors++;
                $this->newLine();
                $this->error("  Ошибка для файла #{$file->id}: {$e->getMessage()}");
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);

        if ($this->option('sync')) {
            $this->info("Обработано: {$processed}, ошибок: {$errors}");
        } else {
            $this->info("В очередь поставлено: {$processed}, ошибок: {$errors}");
            $this->line('Запустите обработчик очередей: php artisan queue:work --queue=images');
        }

        return $errors > 0 ? self::FAILURE : self::SUCCESS;
    }
}
