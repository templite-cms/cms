<?php

namespace Templite\Cms\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Templite\Cms\Http\Resources\FileFolderResource;
use Templite\Cms\Http\Resources\FileResource;
use Templite\Cms\Jobs\ProcessImage;
use Templite\Cms\Models\File;
use Templite\Cms\Models\FileFolder;
use Templite\Cms\Services\FileService;
use Templite\Cms\Services\ImageProcessor;

class MediaController extends Controller
{
    public function __construct(protected FileService $fileService) {}

    /** @OA\Get(path="/media", summary="Список файлов", tags={"Media"}, security={{"bearerAuth":{}}}, @OA\Parameter(name="folder_id", in="query", @OA\Schema(type="integer")), @OA\Parameter(name="type", in="query", @OA\Schema(type="string")), @OA\Parameter(name="search", in="query", @OA\Schema(type="string")), @OA\Response(response=200, description="Файлы")) */
    public function index(Request $request): JsonResponse
    {
        $perPage = min((int) $request->input('per_page', 20), 100);
        $files = $this->fileService->getFiles($request->only(['folder_id', 'type', 'search']), $perPage);
        $files->load('folder');
        return $this->success([
            'files' => FileResource::collection($files),
            'meta' => [
                'current_page' => $files->currentPage(),
                'last_page' => $files->lastPage(),
                'per_page' => $files->perPage(),
                'total' => $files->total(),
            ],
        ]);
    }

    /** @OA\Post(path="/media/upload", summary="Загрузить файл", tags={"Media"}, security={{"bearerAuth":{}}}, @OA\RequestBody(required=true, @OA\MediaType(mediaType="multipart/form-data", @OA\Schema(@OA\Property(property="file", type="string", format="binary"), @OA\Property(property="folder_id", type="integer")))), @OA\Response(response=201, description="Файл загружен")) */
    public function upload(Request $request): JsonResponse
    {
        // TASK-S14 (M-07): Собираем список разрешённых расширений из конфига
        $allowedTypes = config('cms.allowed_file_types', []);
        $allowedExtensions = collect($allowedTypes)->flatten()->unique()->values()->all();
        $mimesRule = !empty($allowedExtensions) ? 'mimes:' . implode(',', $allowedExtensions) : '';

        $rules = [
            'file' => array_filter([
                'required',
                'file',
                'max:' . (config('cms.max_upload_size', 10) * 1024),
                $mimesRule,
            ]),
            'folder_id' => 'nullable|integer|exists:file_folders,id',
            'disk' => 'nullable|string|in:public,local',
            'folder_path' => 'nullable|string|max:255',
        ];

        $request->validate($rules);

        // TASK-S14 (M-07): Дополнительная проверка расширения файла
        // по списку заблокированных (исполняемые, серверные скрипты).
        // Проверяется и оригинальное расширение, и реальное (guessExtension).
        $uploadedFile = $request->file('file');
        $this->assertExtensionNotBlocked($uploadedFile);

        $disk = $request->input('disk', 'public');
        $folderPath = $request->input('folder_path');

        $file = $this->fileService->upload($uploadedFile, $request->folder_id, $disk, $folderPath);

        $this->logAction('upload', 'file', $file->id, ['name' => $file->name, 'mime' => $file->mime]);

        return $this->success(new FileResource($file), 'Файл загружен.', 201);
    }

    /** @OA\Get(path="/media/{id}", summary="Получить файл", tags={"Media"}, security={{"bearerAuth":{}}}, @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")), @OA\Response(response=200, description="Файл")) */
    public function show(int $id): JsonResponse
    {
        return $this->success(new FileResource(File::findOrFail($id)));
    }

    /** @OA\Put(path="/media/{id}", summary="Обновить файл", tags={"Media"}, security={{"bearerAuth":{}}}, @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")), @OA\Response(response=200, description="Обновлено")) */
    public function update(Request $request, int $id): JsonResponse
    {
        $file = File::findOrFail($id);
        $data = $request->validate(['alt' => 'nullable|string|max:255', 'title' => 'nullable|string|max:255', 'folder_id' => 'nullable|integer']);
        $file->update($data);

        $this->logAction('update', 'file', $file->id, ['name' => $file->name]);

        return $this->success(new FileResource($file->fresh()));
    }

    /** @OA\Delete(path="/media/{id}", summary="Удалить файл", tags={"Media"}, security={{"bearerAuth":{}}}, @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")), @OA\Response(response=200, description="Удалено")) */
    public function destroy(int $id): JsonResponse
    {
        $file = File::findOrFail($id);
        $name = $file->name;
        $this->fileService->delete($file);

        $this->logAction('delete', 'file', $id, ['name' => $name]);

        return $this->success(null, 'Файл удалён.');
    }

    /** @OA\Post(path="/media/move", summary="Переместить файлы", tags={"Media"}, security={{"bearerAuth":{}}}, @OA\Response(response=200, description="Перемещено")) */
    public function move(Request $request): JsonResponse
    {
        $data = $request->validate(['file_ids' => 'required|array', 'file_ids.*' => 'integer', 'folder_id' => 'nullable|integer']);
        $this->fileService->moveManyToFolder($data['file_ids'], $data['folder_id'] ?? null);

        $this->logAction('move', 'file', null, ['count' => count($data['file_ids']), 'folder_id' => $data['folder_id'] ?? null]);

        return $this->success(null, 'Файлы перемещены.');
    }

    /** @OA\Post(path="/media/delete-many", summary="Удалить несколько файлов", tags={"Media"}, security={{"bearerAuth":{}}}, @OA\Response(response=200, description="Удалено")) */
    public function deleteMany(Request $request): JsonResponse
    {
        $data = $request->validate(['file_ids' => 'required|array', 'file_ids.*' => 'integer']);
        $count = $this->fileService->deleteMany($data['file_ids']);

        $this->logAction('delete', 'file', null, ['count' => $count]);

        return $this->success(['deleted' => $count], "Удалено файлов: {$count}.");
    }

    /** @OA\Get(path="/media/serve/{id}", summary="Получить содержимое файла (потоковая передача)", tags={"Media"}, security={{"bearerAuth":{}}}, @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")), @OA\Response(response=200, description="Содержимое файла")) */
    public function serve(int $id): StreamedResponse
    {
        $file = File::findOrFail($id);

        abort_unless(
            Storage::disk($file->disk)->exists($file->path),
            404,
            'Файл не найден на диске.'
        );

        $headers = [
            'Content-Type' => $file->mime,
        ];

        // Защита от XSS при прямом доступе к SVG-файлам
        if ($file->mime === 'image/svg+xml') {
            $headers['Content-Security-Policy'] = "script-src 'none'";
            $headers['Content-Disposition'] = 'attachment; filename="' . $file->name . '"';
        }

        return Storage::disk($file->disk)->response($file->path, $file->name, $headers);
    }

    // --- Папки ---

    /** @OA\Get(path="/media/folders", summary="Список папок", tags={"Media Folders"}, security={{"bearerAuth":{}}}, @OA\Response(response=200, description="Папки")) */
    public function folders(): JsonResponse
    {
        $all = FileFolder::withCount(['files', 'children'])->orderBy('order')->get();
        $grouped = $all->groupBy('parent_id');

        $buildTree = function ($parentId = null) use ($grouped, &$buildTree) {
            $items = $grouped->get($parentId, collect());
            return $items->map(function ($folder) use ($buildTree) {
                $folder->setRelation('children', collect($buildTree($folder->id)));
                return $folder;
            });
        };

        $tree = $buildTree(null);
        return $this->success(FileFolderResource::collection($tree));
    }

    /** @OA\Post(path="/media/folders", summary="Создать папку", tags={"Media Folders"}, security={{"bearerAuth":{}}}, @OA\Response(response=201, description="Создано")) */
    public function storeFolder(Request $request): JsonResponse
    {
        $data = $request->validate(['name' => 'required|string|max:255', 'parent_id' => 'nullable|integer|exists:file_folders,id']);
        $folder = $this->fileService->createFolder($data['name'], $data['parent_id'] ?? null);

        $this->logAction('create', 'file_folder', $folder->id, ['name' => $folder->name]);

        return $this->success(new FileFolderResource($folder), 'Папка создана.', 201);
    }

    /** @OA\Put(path="/media/folders/{id}", summary="Переименовать папку", tags={"Media Folders"}, security={{"bearerAuth":{}}}, @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")), @OA\Response(response=200, description="Обновлено")) */
    public function updateFolder(Request $request, int $id): JsonResponse
    {
        $folder = FileFolder::findOrFail($id);
        $folder->update($request->validate(['name' => 'required|string|max:255']));

        $this->logAction('update', 'file_folder', $folder->id, ['name' => $folder->name]);

        return $this->success(new FileFolderResource($folder->fresh()));
    }

    /** @OA\Delete(path="/media/folders/{id}", summary="Удалить папку", tags={"Media Folders"}, security={{"bearerAuth":{}}}, @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")), @OA\Response(response=200, description="Удалено")) */
    public function destroyFolder(int $id): JsonResponse
    {
        $folder = FileFolder::findOrFail($id);
        $name = $folder->name;
        $this->fileService->deleteFolder($folder);

        $this->logAction('delete', 'file_folder', $id, ['name' => $name]);

        return $this->success(null, 'Папка удалена.');
    }

    /** @OA\Post(path="/media/{id}/reprocess", summary="Пересоздать все размеры изображения", tags={"Media"}, security={{"bearerAuth":{}}}, @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")), @OA\Response(response=200, description="Задача поставлена в очередь")) */
    public function reprocess(Request $request, int $id): JsonResponse
    {
        $file = File::findOrFail($id);

        if ($file->type !== 'image') {
            return $this->error('Пересоздание размеров доступно только для изображений.', 422);
        }

        $formats = $request->input('formats');
        $settings = [];
        if ($formats) {
            $settings['formats'] = $formats;
        }

        ProcessImage::dispatch($file->id, $request->input('sizes'));

        $this->logAction('reprocess', 'file', $file->id, ['name' => $file->name]);

        return $this->success(null, 'Изображение поставлено в очередь на обработку.');
    }

    /** @OA\Post(path="/media/{id}/sizes", summary="Создать дополнительный размер изображения", tags={"Media"}, security={{"bearerAuth":{}}}, @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")), @OA\Response(response=200, description="Размер создан")) */
    public function createSize(Request $request, int $id, ImageProcessor $imageProcessor): JsonResponse
    {
        $file = File::findOrFail($id);

        if ($file->type !== 'image') {
            return $this->error('Создание размеров доступно только для изображений.', 422);
        }

        $data = $request->validate([
            'name' => 'required|string|max:50|regex:/^[a-z0-9_-]+$/',
            'width' => 'required_without:height|nullable|integer|min:1|max:5000',
            'height' => 'required_without:width|nullable|integer|min:1|max:5000',
            'fit' => 'nullable|string|in:cover,contain,crop,resize',
            'formats' => 'nullable|array',
            'formats.*' => 'string|in:original,webp,avif',
        ]);

        // Проверяем, что такой размер ещё не существует
        $existingSizes = $file->sizes ?? [];
        if (isset($existingSizes[$data['name']])) {
            return $this->error("Размер \"{$data['name']}\" уже существует.", 422);
        }

        $sizeConfig = [
            $data['name'] => [
                'width' => $data['width'] ?? null,
                'height' => $data['height'] ?? null,
                'fit' => $data['fit'] ?? 'contain',
            ],
        ];

        $settings = [
            'sizes' => $sizeConfig,
            'formats' => $data['formats'] ?? config('cms.default_image_formats', ['original', 'webp']),
        ];

        $imageProcessor->processImage($file, $settings);

        // processImage записывает только новые sizes, нужно merge
        $file->refresh();

        $this->logAction('create_size', 'file', $file->id, ['name' => $file->name, 'size' => $data['name']]);

        return $this->success(new FileResource($file), "Размер \"{$data['name']}\" создан.");
    }

    /** @OA\Delete(path="/media/{id}/sizes/{sizeName}", summary="Удалить размер изображения", tags={"Media"}, security={{"bearerAuth":{}}}, @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")), @OA\Parameter(name="sizeName", in="path", required=true, @OA\Schema(type="string")), @OA\Response(response=200, description="Размер удалён")) */
    public function deleteSize(int $id, string $sizeName): JsonResponse
    {
        $file = File::findOrFail($id);

        $sizes = $file->sizes ?? [];
        if (!isset($sizes[$sizeName])) {
            return $this->error("Размер \"{$sizeName}\" не найден.", 404);
        }

        // Удалить физические файлы этого размера
        foreach ($sizes[$sizeName] as $key => $path) {
            if (in_array($key, ['width', 'height'])) continue;
            Storage::disk($file->disk)->delete($path);
        }

        unset($sizes[$sizeName]);
        $file->update(['sizes' => $sizes ?: null]);

        $this->logAction('delete_size', 'file', $file->id, ['name' => $file->name, 'size' => $sizeName]);

        return $this->success(new FileResource($file->fresh()), "Размер \"{$sizeName}\" удалён.");
    }

    /**
     * TASK-S14 (M-07): Проверяет, что расширение загружаемого файла не входит
     * в список заблокированных (исполняемые файлы, серверные скрипты).
     *
     * Проверяется как клиентское расширение (getClientOriginalExtension),
     * так и расширение, определённое по MIME-типу (guessExtension),
     * чтобы исключить обход через двойные расширения (file.php.jpg → php).
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    private function assertExtensionNotBlocked(\Illuminate\Http\UploadedFile $file): void
    {
        $blocked = array_map(
            'strtolower',
            config('cms.blocked_file_extensions', [])
        );

        if (empty($blocked)) {
            return;
        }

        $clientExt = strtolower($file->getClientOriginalExtension());
        $guessedExt = strtolower($file->guessExtension() ?? '');

        // Проверяем клиентское расширение
        if (in_array($clientExt, $blocked, true)) {
            throw ValidationException::withMessages([
                'file' => "Загрузка файлов с расширением .{$clientExt} запрещена.",
            ]);
        }

        // Проверяем расширение, определённое по MIME-типу
        if ($guessedExt !== '' && in_array($guessedExt, $blocked, true)) {
            throw ValidationException::withMessages([
                'file' => "Загрузка файлов данного типа ({$guessedExt}) запрещена.",
            ]);
        }

        // Проверяем двойные расширения (например, file.php.jpg)
        $originalName = $file->getClientOriginalName();
        $parts = explode('.', $originalName);

        if (count($parts) > 2) {
            // Проверяем все промежуточные расширения
            array_shift($parts); // убираем имя файла
            array_pop($parts);   // убираем последнее (уже проверено как clientExt)

            foreach ($parts as $part) {
                $part = strtolower($part);
                if (in_array($part, $blocked, true)) {
                    throw ValidationException::withMessages([
                        'file' => "Загрузка файлов с расширением .{$part} в имени запрещена.",
                    ]);
                }
            }
        }
    }
}
