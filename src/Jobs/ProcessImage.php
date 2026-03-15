<?php

namespace Templite\Cms\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Templite\Cms\Models\File;
use Templite\Cms\Services\ImageProcessor;

/**
 * Job: обработка изображения (ресайз + конвертация в WebP/AVIF).
 *
 * Ставится в очередь 'images' при загрузке файлов.
 * Создаёт ресайзы по размерам из конфига cms.image_sizes.
 * Опционально конвертирует в WebP и AVIF.
 */
class ProcessImage implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Количество попыток.
     */
    public int $tries = 3;

    /**
     * Таймаут выполнения (секунды).
     */
    public int $timeout = 120;

    /**
     * Create a new job instance.
     */
    public function __construct(
        protected int $fileId,
        protected ?array $sizes = null,
    ) {
        $this->onQueue('images');
    }

    /**
     * Execute the job.
     */
    public function handle(ImageProcessor $imageProcessor): void
    {
        $file = File::find($this->fileId);

        if (!$file) {
            Log::warning("ProcessImage: файл #{$this->fileId} не найден.");
            return;
        }

        if ($file->type !== 'image') {
            Log::info("ProcessImage: файл #{$this->fileId} не является изображением.");
            return;
        }

        try {
            $settings = [];

            if ($this->sizes) {
                $settings['sizes'] = $this->sizes;
            }

            $imageProcessor->processImage($file, $settings);

            Log::info("ProcessImage: файл #{$this->fileId} обработан.");
        } catch (\Throwable $e) {
            Log::error("ProcessImage: ошибка обработки файла #{$this->fileId}: {$e->getMessage()}");
            throw $e;
        }
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error("ProcessImage: не удалось обработать файл #{$this->fileId} после {$this->tries} попыток: {$exception->getMessage()}");
    }
}
