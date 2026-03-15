<?php

namespace Templite\Cms\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Файловый менеджер публичной директории (public/).
 *
 * Позволяет аутентифицированным менеджерам CMS просматривать, редактировать,
 * загружать, удалять и перемещать файлы внутри public_path().
 */
class FileManagerController extends Controller
{
    /**
     * Файлы в корне public/, которые нельзя редактировать/удалять.
     */
    protected const BLACKLISTED_FILES = [
        'index.php',
        '.htaccess',
        'hot',
    ];

    /**
     * Директории в корне public/, которые нельзя просматривать/удалять.
     */
    protected const BLACKLISTED_DIRS = [
        'build',
        'vendor',
        'storage',
        'css',
        'js',
    ];

    /**
     * Расширения файлов, доступные для текстового редактирования.
     */
    protected const EDITABLE_EXTENSIONS = [
        'txt', 'xml', 'json', 'html', 'htm', 'css', 'js', 'svg',
        'htaccess', 'webmanifest', 'yaml', 'yml', 'md', 'csv',
        'log', 'ini', 'conf', 'cfg', 'map',
    ];

    /**
     * Расширения, запрещённые для загрузки (исполняемые / опасные).
     */
    protected const BLOCKED_UPLOAD_EXTENSIONS = [
        'php', 'php3', 'php4', 'php5', 'phtml', 'phar', 'shtml', 'env',
    ];

    /**
     * MIME-типы, запрещённые для загрузки.
     */
    protected const BLOCKED_MIME_TYPES = [
        'application/x-httpd-php',
        'application/x-php',
        'text/x-php',
    ];

    /**
     * Список файлов и папок по указанному пути внутри public/.
     *
     * GET /api/cms/file-manager?path=
     */
    public function index(Request $request): JsonResponse
    {
        $relativePath = trim($request->query('path', ''), '/');

        if ($relativePath !== '') {
            try {
                $absolutePath = $this->resolveAndValidate($relativePath);
            } catch (\Exception $e) {
                return $this->error($e->getMessage(), 403);
            }

            if (!File::isDirectory($absolutePath)) {
                return $this->error('Директория не найдена.', 404);
            }
        } else {
            $absolutePath = public_path();
        }

        $entries = [];

        // Директории
        foreach (File::directories($absolutePath) as $dir) {
            $name = basename($dir);
            $entryRelativePath = $relativePath !== '' ? $relativePath . '/' . $name : $name;

            // Пропускаем запрещённые директории в корне
            if ($relativePath === '' && in_array($name, self::BLACKLISTED_DIRS, true)) {
                continue;
            }

            $entries[] = [
                'name' => $name,
                'type' => 'directory',
                'path' => $entryRelativePath,
                'modified' => date('c', File::lastModified($dir)),
            ];
        }

        // Файлы
        foreach (File::files($absolutePath) as $file) {
            $name = $file->getFilename();
            $entryRelativePath = $relativePath !== '' ? $relativePath . '/' . $name : $name;

            // Пропускаем запрещённые файлы в корне
            if ($relativePath === '' && in_array($name, self::BLACKLISTED_FILES, true)) {
                continue;
            }

            $extension = $file->getExtension();

            $entries[] = [
                'name' => $name,
                'type' => 'file',
                'path' => $entryRelativePath,
                'size' => $file->getSize(),
                'extension' => $extension,
                'modified' => date('c', $file->getMTime()),
                'editable' => $this->isEditable($extension),
            ];
        }

        return $this->success([
            'path' => $relativePath,
            'entries' => $entries,
        ]);
    }

    /**
     * Чтение содержимого текстового файла.
     *
     * GET /api/cms/file-manager/file?path=
     */
    public function showFile(Request $request): JsonResponse
    {
        $relativePath = $request->query('path', '');
        if (empty($relativePath)) {
            return $this->error('Параметр path обязателен.', 422);
        }

        try {
            $absolutePath = $this->resolveAndValidate($relativePath);
            $this->validateNotBlacklisted($relativePath);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), 403);
        }

        if (!File::exists($absolutePath) || File::isDirectory($absolutePath)) {
            return $this->error('Файл не найден.', 404);
        }

        $extension = pathinfo($absolutePath, PATHINFO_EXTENSION);
        if (!$this->isEditable($extension)) {
            return $this->error('Этот тип файла нельзя редактировать.', 403);
        }

        return $this->success([
            'path' => $relativePath,
            'name' => basename($absolutePath),
            'content' => File::get($absolutePath),
            'language' => $this->detectLanguage($absolutePath),
            'size' => File::size($absolutePath),
        ]);
    }

    /**
     * Сохранение содержимого текстового файла.
     *
     * PUT /api/cms/file-manager/file
     * Body: { path: string, content: string }
     */
    public function updateFile(Request $request): JsonResponse
    {
        $request->validate([
            'path' => 'required|string',
            'content' => 'present|string',
        ]);

        $relativePath = $request->input('path');

        try {
            $absolutePath = $this->resolveAndValidate($relativePath);
            $this->validateNotBlacklisted($relativePath);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), 403);
        }

        if (!File::exists($absolutePath) || File::isDirectory($absolutePath)) {
            return $this->error('Файл не найден.', 404);
        }

        $extension = pathinfo($absolutePath, PATHINFO_EXTENSION);
        if (!$this->isEditable($extension)) {
            return $this->error('Этот тип файла нельзя редактировать.', 403);
        }

        File::put($absolutePath, $request->input('content'));

        $this->logAction('update', 'file', null, ['path' => $relativePath]);

        return $this->success(null, 'Файл сохранён.');
    }

    /**
     * Загрузка файлов в указанную директорию.
     *
     * POST /api/cms/file-manager/upload
     * Body: FormData с path + files[]
     */
    public function upload(Request $request): JsonResponse
    {
        $request->validate([
            'path' => 'nullable|string',
            'files' => 'required|array|min:1',
            'files.*' => 'required|file|max:10240', // 10 MB
        ]);

        $relativePath = trim($request->input('path', ''), '/');

        try {
            if ($relativePath !== '') {
                $absolutePath = $this->resolveAndValidate($relativePath);
                $this->validateNotBlacklisted($relativePath);
            } else {
                $absolutePath = public_path();
            }
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), 403);
        }

        if (!File::isDirectory($absolutePath)) {
            return $this->error('Целевая директория не найдена.', 404);
        }

        $uploaded = [];
        foreach ($request->file('files') as $file) {
            $name = $file->getClientOriginalName();

            // Проверка основного расширения
            $ext = strtolower($file->getClientOriginalExtension());
            if (in_array($ext, self::BLOCKED_UPLOAD_EXTENSIONS, true)) {
                return $this->error("Загрузка файлов .{$ext} запрещена.", 422);
            }

            // Проверка двойных расширений (file.php.jpg, file.phtml.txt и т.д.)
            if ($this->hasBlockedExtensionInName($name)) {
                return $this->error('Имя файла содержит запрещённое расширение.', 422);
            }

            // Проверка MIME-типа
            $mime = $file->getMimeType();
            if (in_array($mime, self::BLOCKED_MIME_TYPES, true)) {
                return $this->error('Загрузка файлов с данным MIME-типом запрещена.', 422);
            }

            $file->move($absolutePath, $name);
            $uploaded[] = $relativePath !== '' ? $relativePath . '/' . $name : $name;
        }

        $this->logAction('upload', 'file', null, ['path' => $relativePath, 'count' => count($uploaded), 'files' => $uploaded]);

        return $this->success([
            'uploaded' => $uploaded,
        ], 'Файлы загружены.');
    }

    /**
     * Создание папки.
     *
     * POST /api/cms/file-manager/folder
     * Body: { path: string, name: string }
     */
    public function createFolder(Request $request): JsonResponse
    {
        $request->validate([
            'path' => 'nullable|string',
            'name' => ['required', 'string', 'regex:/^[a-zA-Z0-9_\-\.]+$/'],
        ]);

        $relativePath = trim($request->input('path', ''), '/');
        $name = $request->input('name');

        try {
            if ($relativePath !== '') {
                $parentAbsolute = $this->resolveAndValidate($relativePath);
                $this->validateNotBlacklisted($relativePath);
            } else {
                $parentAbsolute = public_path();
            }
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), 403);
        }

        if (!File::isDirectory($parentAbsolute)) {
            return $this->error('Родительская директория не найдена.', 404);
        }

        $newFolderPath = $parentAbsolute . DIRECTORY_SEPARATOR . $name;

        if (File::exists($newFolderPath)) {
            return $this->error('Папка или файл с таким именем уже существует.', 409);
        }

        File::makeDirectory($newFolderPath, 0755, false);

        $newRelativePath = $relativePath !== '' ? $relativePath . '/' . $name : $name;

        $this->logAction('create', 'file_folder', null, ['name' => $name, 'path' => $newRelativePath]);

        return $this->success([
            'path' => $newRelativePath,
        ], 'Папка создана.');
    }

    /**
     * Создание нового пустого файла.
     *
     * POST /api/cms/file-manager/create-file
     * Body: { path: string, name: string }
     */
    public function createFile(Request $request): JsonResponse
    {
        $request->validate([
            'path' => 'nullable|string',
            'name' => ['required', 'string', 'max:255', 'regex:/^[a-zA-Z0-9_\-\.]+$/'],
        ]);

        $relativePath = trim($request->input('path', ''), '/');
        $name = $request->input('name');

        try {
            if ($relativePath !== '') {
                $parentAbsolute = $this->resolveAndValidate($relativePath);
                $this->validateNotBlacklisted($relativePath);
            } else {
                $parentAbsolute = public_path();
            }
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), 403);
        }

        if (!File::isDirectory($parentAbsolute)) {
            return $this->error('Родительская директория не найдена.', 404);
        }

        $newFilePath = $parentAbsolute . DIRECTORY_SEPARATOR . $name;

        if (File::exists($newFilePath)) {
            return $this->error('Файл или папка с таким именем уже существует.', 409);
        }

        $newRelativePath = $relativePath !== '' ? $relativePath . '/' . $name : $name;

        // Проверяем, что расширение редактируемое
        $ext = pathinfo($name, PATHINFO_EXTENSION);
        if (!$this->isEditable($ext)) {
            return $this->error('Создание файлов с таким расширением не поддерживается.', 422);
        }

        File::put($newFilePath, '');

        $this->logAction('create', 'file', null, ['name' => $name, 'path' => $newRelativePath]);

        return $this->success([
            'path' => $newRelativePath,
        ], 'Файл создан.');
    }

    /**
     * Удаление файла или пустой папки.
     *
     * POST /api/cms/file-manager/delete
     * Body: { path: string }
     */
    public function deleteFile(Request $request): JsonResponse
    {
        $request->validate([
            'path' => 'required|string',
        ]);

        $relativePath = $request->input('path');

        try {
            $absolutePath = $this->resolveAndValidate($relativePath);
            $this->validateNotBlacklisted($relativePath);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), 403);
        }

        if (!File::exists($absolutePath)) {
            return $this->error('Файл или папка не найдены.', 404);
        }

        $isDir = File::isDirectory($absolutePath);

        if ($isDir) {
            // Разрешаем удалять только пустые папки
            if (count(File::allFiles($absolutePath)) > 0 || count(File::directories($absolutePath)) > 0) {
                return $this->error('Удалять можно только пустые папки.', 422);
            }
            File::deleteDirectory($absolutePath);
        } else {
            File::delete($absolutePath);
        }

        $this->logAction('delete', $isDir ? 'file_folder' : 'file', null, ['path' => $relativePath]);

        return $this->success(null, 'Удалено.');
    }

    /**
     * Переименование файла или папки.
     *
     * PATCH /api/cms/file-manager/rename
     * Body: { path: string, name: string }
     */
    public function rename(Request $request): JsonResponse
    {
        $request->validate([
            'path' => 'required|string',
            'name' => ['required', 'string', 'regex:/^[a-zA-Z0-9_\-\.]+$/'],
        ]);

        $relativePath = $request->input('path');
        $newName = $request->input('name');

        try {
            $absolutePath = $this->resolveAndValidate($relativePath);
            $this->validateNotBlacklisted($relativePath);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), 403);
        }

        if (!File::exists($absolutePath)) {
            return $this->error('Файл или папка не найдены.', 404);
        }

        $parentDir = dirname($absolutePath);
        $newAbsolutePath = $parentDir . DIRECTORY_SEPARATOR . $newName;

        if (File::exists($newAbsolutePath)) {
            return $this->error('Файл или папка с таким именем уже существует.', 409);
        }

        File::move($absolutePath, $newAbsolutePath);

        // Вычисляем новый относительный путь
        $parentRelative = dirname($relativePath);
        $newRelativePath = $parentRelative !== '.' ? $parentRelative . '/' . $newName : $newName;

        $this->logAction('update', 'file', null, ['path' => $relativePath, 'new_name' => $newName]);

        return $this->success([
            'path' => $newRelativePath,
        ], 'Переименовано.');
    }

    /**
     * Перемещение файла/папки в другую директорию.
     *
     * PATCH /api/cms/file-manager/move
     * Body: { path: string, destination: string }
     */
    public function move(Request $request): JsonResponse
    {
        $request->validate([
            'path' => 'required|string',
            'destination' => 'nullable|string',
        ]);

        $relativePath = $request->input('path');
        $destination = trim($request->input('destination', ''), '/');

        try {
            $absolutePath = $this->resolveAndValidate($relativePath);
            $this->validateNotBlacklisted($relativePath);

            if ($destination !== '') {
                $destAbsolute = $this->resolveAndValidate($destination);
                $this->validateNotBlacklisted($destination);
            } else {
                $destAbsolute = public_path();
            }
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), 403);
        }

        if (!File::exists($absolutePath)) {
            return $this->error('Файл или папка не найдены.', 404);
        }

        if (!File::isDirectory($destAbsolute)) {
            return $this->error('Целевая директория не найдена.', 404);
        }

        $name = basename($absolutePath);
        $newAbsolutePath = $destAbsolute . DIRECTORY_SEPARATOR . $name;

        if (File::exists($newAbsolutePath)) {
            return $this->error('В целевой директории уже существует файл или папка с таким именем.', 409);
        }

        File::move($absolutePath, $newAbsolutePath);

        $newRelativePath = $destination !== '' ? $destination . '/' . $name : $name;

        $this->logAction('move', 'file', null, ['path' => $relativePath, 'destination' => $newRelativePath]);

        return $this->success([
            'path' => $newRelativePath,
        ], 'Перемещено.');
    }

    /**
     * Скачивание файла.
     *
     * GET /api/cms/file-manager/download?path=
     */
    public function download(Request $request): BinaryFileResponse|JsonResponse
    {
        $relativePath = $request->query('path', '');
        if (empty($relativePath)) {
            return $this->error('Параметр path обязателен.', 422);
        }

        try {
            $absolutePath = $this->resolveAndValidate($relativePath);
            $this->validateNotBlacklisted($relativePath);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), 403);
        }

        if (!File::exists($absolutePath) || File::isDirectory($absolutePath)) {
            return $this->error('Файл не найден.', 404);
        }

        return response()->download($absolutePath);
    }

    // =========================================================================
    // Helper methods
    // =========================================================================

    /**
     * Разрешить относительный путь в абсолютный и проверить,
     * что результат находится внутри public_path().
     *
     * Для несуществующих путей валидируется родительская директория.
     *
     * @throws \RuntimeException если путь выходит за пределы public_path()
     */
    protected function resolveAndValidate(string $relativePath): string
    {
        $relativePath = trim($relativePath, '/');

        if (empty($relativePath)) {
            return public_path();
        }

        // Предварительная проверка на подозрительные сегменты
        if (str_contains($relativePath, '..')) {
            throw new \RuntimeException('Недопустимый путь.');
        }

        $absolutePath = public_path($relativePath);

        // Если путь существует — используем realpath для канонизации
        if (File::exists($absolutePath)) {
            $realPath = realpath($absolutePath);
            $publicReal = realpath(public_path());

            if ($realPath === false || $publicReal === false) {
                throw new \RuntimeException('Не удалось разрешить путь.');
            }

            // Путь должен быть внутри public/ (или равен ему)
            if ($realPath !== $publicReal && !str_starts_with($realPath, $publicReal . DIRECTORY_SEPARATOR)) {
                throw new \RuntimeException('Доступ к этому пути запрещён.');
            }

            return $realPath;
        }

        // Путь не существует — проверяем родительскую директорию
        $parentAbsolute = dirname($absolutePath);

        if (File::exists($parentAbsolute)) {
            $parentReal = realpath($parentAbsolute);
            $publicReal = realpath(public_path());

            if ($parentReal === false || $publicReal === false) {
                throw new \RuntimeException('Не удалось разрешить путь.');
            }

            if ($parentReal !== $publicReal && !str_starts_with($parentReal, $publicReal . DIRECTORY_SEPARATOR)) {
                throw new \RuntimeException('Доступ к этому пути запрещён.');
            }

            // Возвращаем канонический родительский путь + имя файла
            return $parentReal . DIRECTORY_SEPARATOR . basename($absolutePath);
        }

        throw new \RuntimeException('Путь не найден.');
    }

    /**
     * Проверить, что первый сегмент пути не находится в чёрном списке.
     *
     * @throws \RuntimeException если путь заблокирован
     */
    protected function validateNotBlacklisted(string $relativePath): void
    {
        $relativePath = trim($relativePath, '/');

        if (empty($relativePath)) {
            return;
        }

        // Извлекаем первый сегмент пути
        $segments = explode('/', $relativePath);
        $firstSegment = $segments[0];

        if (in_array($firstSegment, self::BLACKLISTED_FILES, true)) {
            throw new \RuntimeException('Этот файл защищён от изменений.');
        }

        if (in_array($firstSegment, self::BLACKLISTED_DIRS, true)) {
            throw new \RuntimeException('Эта директория защищена от изменений.');
        }
    }

    /**
     * Проверить, является ли расширение файла текстово-редактируемым.
     */
    protected function isEditable(string $extension): bool
    {
        return in_array(strtolower($extension), self::EDITABLE_EXTENSIONS, true);
    }

    /**
     * Проверить, содержит ли имя файла запрещённое расширение в любой позиции.
     * Например: file.php.jpg, image.phtml.png — блокируются.
     */
    protected function hasBlockedExtensionInName(string $filename): bool
    {
        $parts = explode('.', strtolower($filename));

        // Убираем первую часть (само имя файла) и последнюю (основное расширение, проверяется отдельно)
        // Проверяем все промежуточные «расширения»
        if (count($parts) <= 2) {
            return false;
        }

        // Проверяем все части кроме первой (имя) — включая промежуточные и финальное
        $extensions = array_slice($parts, 1);
        foreach ($extensions as $part) {
            if (in_array($part, self::BLOCKED_UPLOAD_EXTENSIONS, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Определение языка для CodeMirror по расширению файла.
     */
    protected function detectLanguage(string $path): string
    {
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        return match ($ext) {
            'js', 'map' => 'javascript',
            'json', 'webmanifest' => 'json',
            'css', 'scss' => 'css',
            'html', 'htm' => 'html',
            'xml', 'svg' => 'xml',
            'yaml', 'yml' => 'yaml',
            'md' => 'markdown',
            'ini', 'conf', 'cfg' => 'ini',
            'csv' => 'csv',
            'log', 'txt' => 'text',
            default => 'text',
        };
    }
}
