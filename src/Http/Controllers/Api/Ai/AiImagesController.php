<?php

namespace Templite\Cms\Http\Controllers\Api\Ai;

use Illuminate\Http\JsonResponse;
use Templite\Cms\Http\Controllers\Api\Controller;
use Templite\Cms\Models\File;
use Templite\Cms\Services\FileService;

/**
 * Подтверждение картинок, сгенерированных ассистентом.
 *
 * До подтверждения файл лежит в карантине: его не видно в библиотеке
 * и он не может попасть в поле блока.
 */
class AiImagesController extends Controller
{
    public function __construct(private readonly FileService $files)
    {
    }

    public function approve(int $id): JsonResponse
    {
        $file = File::find($id);

        if (! $file) {
            return $this->error('Файл не найден.', 404);
        }

        if (! $this->ownedByCurrentManager($file)) {
            return $this->error('Файл не найден.', 404);
        }

        // Иначе этим маршрутом можно было бы «подтверждать» любой файл библиотеки.
        if (! $file->ai_pending) {
            return $this->error('Изображение уже подтверждено.', 422);
        }

        $file->update(['ai_pending' => false]);

        return $this->success([
            'file_id' => $file->id,
            'name' => $file->name,
            'url' => $file->url,
        ], 'Изображение подтверждено.');
    }

    /**
     * Отклонённая картинка удаляется сразу: она нигде не используется,
     * а в библиотеке только мешала бы.
     */
    public function reject(int $id): JsonResponse
    {
        $file = File::find($id);

        if (! $file) {
            return $this->error('Файл не найден.', 404);
        }

        if (! $this->ownedByCurrentManager($file)) {
            return $this->error('Файл не найден.', 404);
        }

        if (! $file->ai_pending) {
            return $this->error('Изображение уже подтверждено — удалите его через медиабиблиотеку.', 422);
        }

        $this->files->delete($file);

        return $this->success(null, 'Изображение удалено.');
    }

    /**
     * Картинка в карантине принадлежит тому, кто её заказал. Чужую не отдаём
     * и не удаляем — отвечаем 404, чтобы не подтверждать её существование.
     */
    private function ownedByCurrentManager(File $file): bool
    {
        $manager = auth()->user() ?? auth('manager')->user();

        if (! $manager) {
            return false;
        }

        if ($manager->hasPermission('*')) {
            return true;
        }

        $author = $file->meta['ai_manager_id'] ?? null;

        // Картинки, сгенерированные до появления автора в meta, остаются
        // доступными владельцу установки — их подтвердит админ.
        return $author !== null && (int) $author === (int) $manager->id;
    }
}
