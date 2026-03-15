<?php

namespace Templite\Cms\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Templite\Cms\Jobs\ProcessImage;
use Symfony\Component\HttpFoundation\Response;

/**
 * Middleware для оптимизации изображений при загрузке.
 *
 * При обнаружении загруженных файлов-изображений в запросе
 * ставит задачу в очередь на оптимизацию (ресайз + конвертация).
 * Работает после основной обработки запроса (after middleware).
 */
class OptimizeImages
{
    /**
     * Типы файлов, подлежащие оптимизации.
     */
    protected array $imageExtensions = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'tiff'];

    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        // Оптимизация только для успешных запросов с файлами
        if (!$request->hasFile('file') && !$request->hasFile('files')) {
            return $response;
        }

        if ($response->getStatusCode() >= 200 && $response->getStatusCode() < 300) {
            $this->processUploadedImages($response);
        }

        return $response;
    }

    /**
     * Обработка загруженных изображений из ответа.
     */
    protected function processUploadedImages(Response $response): void
    {
        // Извлекаем данные из JSON ответа
        $content = $response->getContent();
        if (!$content) {
            return;
        }

        $data = json_decode($content, true);
        if (!$data) {
            return;
        }

        // Ищем file_id в ответе для постановки в очередь
        $fileId = $data['data']['id'] ?? $data['data']['file']['id'] ?? null;

        if ($fileId && $this->shouldOptimize($data)) {
            ProcessImage::dispatch((int) $fileId)
                ->onQueue('images');
        }
    }

    /**
     * Определить, нужна ли оптимизация.
     */
    protected function shouldOptimize(array $data): bool
    {
        $type = $data['data']['type'] ?? $data['data']['file']['type'] ?? null;
        $extension = $data['data']['extension'] ?? $data['data']['file']['extension'] ?? null;

        // Оптимизируем только изображения
        if ($type === 'image' || in_array(strtolower($extension ?? ''), $this->imageExtensions)) {
            return true;
        }

        return false;
    }
}
