<?php

namespace Templite\Cms\Services;

use Illuminate\Support\Facades\Storage;
use Intervention\Image\ImageManager;
use Intervention\Image\Drivers\Gd\Driver as GdDriver;
use Intervention\Image\Drivers\Imagick\Driver as ImagickDriver;
use Templite\Cms\Models\File;

class ImageProcessor
{
    protected ImageManager $manager;

    public function __construct()
    {
        // Предпочитаем Imagick, если доступен, иначе GD
        $driver = extension_loaded('imagick') ? new ImagickDriver() : new GdDriver();
        $this->manager = new ImageManager($driver);
    }

    /**
     * Создать все ресайзы для файла по настройкам.
     */
    /**
     * Форматы, которые не поддерживаются Intervention Image.
     */
    protected const UNSUPPORTED_EXTENSIONS = ['svg', 'ico', 'bmp', 'tiff', 'tif'];

    public function processImage(File $file, array $settings): void
    {
        if (!$file->isImage()) {
            return;
        }

        // Пропускаем форматы, которые Intervention Image не может декодировать
        $extension = strtolower(pathinfo($file->path, PATHINFO_EXTENSION));
        if (in_array($extension, self::UNSUPPORTED_EXTENSIONS)) {
            return;
        }

        $sizes = [];
        $sizeSettings = $settings['sizes'] ?? config('cms.default_image_sizes', []);
        $formats = $settings['formats'] ?? config('cms.default_image_formats', ['original', 'webp']);
        $quality = $settings['quality'] ?? config('cms.default_image_quality', 85);

        $sourcePath = Storage::disk($file->disk)->path($file->path);

        // Проверяем существование файла на диске
        if (!file_exists($sourcePath)) {
            return;
        }

        foreach ($sizeSettings as $sizeName => $sizeConfig) {
            $width = $sizeConfig['width'] ?? null;
            $height = $sizeConfig['height'] ?? null;
            $fit = $sizeConfig['fit'] ?? 'cover';

            if (!$width && !$height) {
                continue;
            }

            $image = $this->manager->read($sourcePath);

            // Применяем ресайз
            $image = $this->applyResize($image, $width, $height, $fit);

            $sizes[$sizeName] = [
                'width' => $width,
                'height' => $height,
            ];

            // Сохраняем в оригинальном формате
            $originalResizedPath = $this->generateResizedPath($file->path, $sizeName, $this->getOriginalExtension($file));
            $this->saveImage($image, $originalResizedPath, $file->disk, $quality);
            $sizes[$sizeName]['original'] = $originalResizedPath;

            // WebP
            if (in_array('webp', $formats)) {
                $webpPath = $this->generateResizedPath($file->path, $sizeName, 'webp');
                $this->saveImageAs($image, $webpPath, $file->disk, 'webp', $quality);
                $sizes[$sizeName]['webp'] = $webpPath;
            }

            // AVIF
            if (in_array('avif', $formats) && $this->supportsAvif()) {
                $avifPath = $this->generateResizedPath($file->path, $sizeName, 'avif');
                $this->saveImageAs($image, $avifPath, $file->disk, 'avif', $quality);
                $sizes[$sizeName]['avif'] = $avifPath;
            }
        }

        // Merge с существующими sizes (для добавления отдельных размеров)
        $existingSizes = $file->sizes ?? [];
        $mergedSizes = array_merge($existingSizes, $sizes);
        $file->update(['sizes' => $mergedSizes ?: null]);
    }

    /**
     * Пересоздать все ресайзы для файла.
     */
    public function reprocessImage(File $file, array $settings): void
    {
        // Удалить существующие ресайзы
        $this->deleteResizes($file);

        // Создать заново
        $this->processImage($file, $settings);
    }

    /**
     * Удалить все ресайзы файла.
     */
    public function deleteResizes(File $file): void
    {
        if (empty($file->sizes)) {
            return;
        }

        foreach ($file->sizes as $sizeData) {
            foreach ($sizeData as $key => $path) {
                if (in_array($key, ['width', 'height'])) {
                    continue;
                }
                Storage::disk($file->disk)->delete($path);
            }
        }

        $file->update(['sizes' => null]);
    }

    /**
     * Применить ресайз к изображению.
     */
    protected function applyResize($image, ?int $width, ?int $height, string $fit)
    {
        switch ($fit) {
            case 'cover':
                $image->cover($width, $height);
                break;

            case 'contain':
                $image->scale($width, $height);
                break;

            case 'crop':
                $image->cover($width, $height);
                break;

            case 'resize':
                $image->resize($width, $height);
                break;

            default:
                if ($width && $height) {
                    $image->cover($width, $height);
                } elseif ($width) {
                    $image->scale(width: $width);
                } elseif ($height) {
                    $image->scale(height: $height);
                }
                break;
        }

        return $image;
    }

    /**
     * Сохранить изображение на диск.
     */
    protected function saveImage($image, string $path, string $disk, int $quality): void
    {
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $encoded = match ($extension) {
            'png' => $image->toPng(),
            'gif' => $image->toGif(),
            'webp' => $image->toWebp($quality),
            'avif' => $image->toAvif($quality),
            default => $image->toJpeg($quality),
        };
        Storage::disk($disk)->put($path, (string) $encoded);
    }

    /**
     * Сохранить изображение в указанном формате.
     */
    protected function saveImageAs($image, string $path, string $disk, string $format, int $quality): void
    {
        $encoded = match ($format) {
            'webp' => $image->toWebp($quality),
            'avif' => $image->toAvif($quality),
            'png' => $image->toPng(),
            'gif' => $image->toGif(),
            default => $image->toJpeg($quality),
        };

        Storage::disk($disk)->put($path, (string) $encoded);
    }

    /**
     * Генерация пути для ресайзнутого файла.
     */
    protected function generateResizedPath(string $originalPath, string $sizeName, string $extension): string
    {
        $info = pathinfo($originalPath);
        $dir = $info['dirname'];
        $name = $info['filename'];

        return "{$dir}/{$name}_{$sizeName}.{$extension}";
    }

    /**
     * Получить расширение файла.
     */
    protected function getOriginalExtension(File $file): string
    {
        $ext = pathinfo($file->path, PATHINFO_EXTENSION);
        return $ext ?: 'jpg';
    }

    /**
     * Проверить поддержку AVIF.
     */
    public function supportsAvif(): bool
    {
        return function_exists('imageavif');
    }
}
