<?php

namespace Templite\Cms\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use enshrined\svgSanitize\Sanitizer;
use Templite\Cms\Models\File;
use Templite\Cms\Helpers\StringHelper;
use Templite\Cms\Models\FileFolder;

class FileService
{
    /**
     * Загрузить файл.
     */
    public function upload(UploadedFile $uploadedFile, ?int $folderId = null, string $disk = 'public', ?string $customPath = null): File
    {
        $originalName = $uploadedFile->getClientOriginalName();
        $mime = $uploadedFile->getMimeType();
        $size = $uploadedFile->getSize();
        $type = $this->detectType($mime);

        // Генерация уникального пути
        $fileName = Str::uuid() . '.' . $uploadedFile->getClientOriginalExtension();

        if ($customPath) {
            $directory = rtrim($customPath, '/');
            $path = "{$directory}/{$fileName}";
        } else {
            $datePath = date('Y/m/d');
            $directory = "uploads/{$datePath}";
            $path = "{$directory}/{$fileName}";
        }

        // Санитизация SVG перед сохранением (защита от Stored XSS)
        if ($this->isSvg($uploadedFile)) {
            $this->sanitizeSvg($uploadedFile);
        }

        // Сохранение на диск
        Storage::disk($disk)->putFileAs(
            $directory,
            $uploadedFile,
            $fileName
        );

        // Извлечение мета-данных для изображений
        $meta = [];
        if ($type === 'image') {
            $meta = $this->extractImageMeta($uploadedFile);
        }

        // Создание записи в БД
        $file = File::create([
            'name' => $originalName,
            'path' => $path,
            'disk' => $disk,
            'size' => $size,
            'mime' => $mime,
            'type' => $type,
            'folder_id' => $folderId,
            'meta' => $meta ?: null,
        ]);

        return $file;
    }

    /**
     * Удалить файл с диска и из БД.
     */
    public function delete(File $file): void
    {
        $file->deleteWithFiles();
    }

    /**
     * Удалить несколько файлов.
     */
    public function deleteMany(array $fileIds): int
    {
        $files = File::whereIn('id', $fileIds)->get();
        $count = 0;

        foreach ($files as $file) {
            $this->delete($file);
            $count++;
        }

        return $count;
    }

    /**
     * Переместить файл в другую папку.
     */
    public function moveToFolder(File $file, ?int $folderId): File
    {
        $file->update(['folder_id' => $folderId]);
        return $file->fresh();
    }

    /**
     * Переместить несколько файлов в папку.
     */
    public function moveManyToFolder(array $fileIds, ?int $folderId): int
    {
        return File::whereIn('id', $fileIds)->update(['folder_id' => $folderId]);
    }

    /**
     * Обновить мета-данные файла (alt, title).
     */
    public function updateMeta(File $file, array $data): File
    {
        $file->update(array_filter([
            'alt' => $data['alt'] ?? null,
            'title' => $data['title'] ?? null,
        ], fn($v) => $v !== null));

        return $file->fresh();
    }

    /**
     * Получить файлы по фильтрам.
     */
    public function getFiles(array $filters = [], int $perPage = 20, int $maxPerPage = 100)
    {
        $query = File::query();

        if (!empty($filters['folder_id'])) {
            $query->where('folder_id', $filters['folder_id']);
        } elseif (array_key_exists('folder_id', $filters) && $filters['folder_id'] === null) {
            $query->whereNull('folder_id');
        }

        if (!empty($filters['type'])) {
            $query->where('type', $filters['type']);
        }

        if (!empty($filters['search'])) {
            $query->where('name', 'like', '%' . StringHelper::escapeLike($filters['search']) . '%');
        }

        return $query->orderByDesc('created_at')->paginate(min($perPage, $maxPerPage));
    }

    /**
     * Создать папку.
     */
    public function createFolder(string $name, ?int $parentId = null): FileFolder
    {
        return FileFolder::create([
            'name' => $name,
            'parent_id' => $parentId,
        ]);
    }

    /**
     * Удалить папку и все файлы внутри.
     */
    public function deleteFolder(FileFolder $folder): void
    {
        // Удаляем все файлы в папке
        $files = File::where('folder_id', $folder->id)->get();
        foreach ($files as $file) {
            $this->delete($file);
        }

        // Удаляем подпапки рекурсивно
        $subfolders = FileFolder::where('parent_id', $folder->id)->get();
        foreach ($subfolders as $subfolder) {
            $this->deleteFolder($subfolder);
        }

        $folder->delete();
    }

    /**
     * Определить тип файла по MIME.
     */
    protected function detectType(string $mime): string
    {
        if (str_starts_with($mime, 'image/')) {
            return 'image';
        }

        if (str_starts_with($mime, 'video/')) {
            return 'video';
        }

        if (in_array($mime, [
            'application/pdf',
            'application/msword',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'application/vnd.ms-excel',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'text/plain',
            'text/csv',
        ])) {
            return 'document';
        }

        if (in_array($mime, [
            'application/zip',
            'application/x-rar-compressed',
            'application/x-7z-compressed',
            'application/gzip',
        ])) {
            return 'archive';
        }

        return 'other';
    }

    /**
     * Извлечь мета-данные изображения.
     */
    protected function extractImageMeta(UploadedFile $file): array
    {
        $meta = [];

        $imageInfo = @getimagesize($file->getRealPath());
        if ($imageInfo) {
            $meta['width'] = $imageInfo[0];
            $meta['height'] = $imageInfo[1];
        }

        return $meta;
    }

    /**
     * Проверить, является ли загружаемый файл SVG.
     */
    protected function isSvg(UploadedFile $file): bool
    {
        $mime = $file->getMimeType();
        $extension = strtolower($file->getClientOriginalExtension());

        return $mime === 'image/svg+xml' || $extension === 'svg';
    }

    /**
     * Санитизация SVG-файла: удаление JavaScript, event-handler'ов
     * и прочих потенциально опасных элементов (защита от Stored XSS).
     *
     * Перезаписывает содержимое загруженного файла очищенной версией.
     *
     * @throws \RuntimeException Если SVG не может быть санитизирован
     */
    protected function sanitizeSvg(UploadedFile $file): void
    {
        if (!config('cms.sanitize_svg', true)) {
            return;
        }

        $path = $file->getRealPath();
        $dirtySvg = file_get_contents($path);

        if ($dirtySvg === false) {
            throw new \RuntimeException('Не удалось прочитать SVG-файл для санитизации.');
        }

        $sanitizer = new Sanitizer();

        // Удаляем удалённые ресурсы (внешние ссылки)
        $sanitizer->removeRemoteReferences(true);

        $cleanSvg = $sanitizer->sanitize($dirtySvg);

        if ($cleanSvg === false || trim($cleanSvg) === '') {
            throw new \RuntimeException('SVG-файл не прошёл санитизацию: содержимое полностью удалено или повреждено.');
        }

        file_put_contents($path, $cleanSvg);
    }
}
