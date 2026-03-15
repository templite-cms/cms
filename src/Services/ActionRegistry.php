<?php

namespace Templite\Cms\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Templite\Cms\Contracts\BlockActionInterface;
use Templite\Cms\Contracts\RegistryInterface;
use Templite\Cms\Models\Action;

class ActionRegistry implements RegistryInterface
{
    /**
     * Реестр actions: [source => [slug => class/path]]
     */
    protected array $registry = [
        'app' => [],
        'storage' => [],
        'vendor' => [],
    ];

    protected array $priority = ['app', 'storage', 'vendor'];

    /**
     * {@inheritdoc}
     */
    public function find(string $slug): mixed
    {
        foreach ($this->priority as $source) {
            if (isset($this->registry[$source][$slug])) {
                return $this->registry[$source][$slug];
            }
        }

        return null;
    }

    /**
     * {@inheritdoc}
     */
    public function all(): Collection
    {
        $result = [];

        foreach (array_reverse($this->priority) as $source) {
            foreach ($this->registry[$source] as $slug => $entity) {
                $result[$slug] = $entity;
            }
        }

        return collect($result);
    }

    /**
     * {@inheritdoc}
     */
    public function exists(string $slug): bool
    {
        return $this->find($slug) !== null;
    }

    /**
     * {@inheritdoc}
     */
    public function register(string $slug, mixed $entity, string $source): void
    {
        if (!isset($this->registry[$source])) {
            $this->registry[$source] = [];
        }

        $this->registry[$source][$slug] = $entity;
    }

    /**
     * {@inheritdoc}
     */
    public function fromSource(string $source): Collection
    {
        return collect($this->registry[$source] ?? []);
    }

    /**
     * Создать экземпляр action по slug.
     */
    public function resolve(string $slug): ?BlockActionInterface
    {
        $entry = $this->find($slug);

        if ($entry === null) {
            return null;
        }

        // Если это класс (строка)
        if (is_string($entry) && class_exists($entry)) {
            $instance = app($entry);
            if ($instance instanceof BlockActionInterface) {
                return $instance;
            }
        }

        // Если это массив с path (файл из storage)
        if (is_array($entry) && isset($entry['path'])) {
            return $this->loadFromFile($entry['path']);
        }

        return null;
    }

    /**
     * Загрузить action из файла с проверкой целостности и валидацией кода.
     *
     * 1. Проверяет SHA-256 хэш файла против сохранённого в БД
     * 2. Валидирует код через ActionCodeValidator (токенизатор)
     * 3. Логирует нарушения целостности
     */
    protected function loadFromFile(string $path): ?BlockActionInterface
    {
        if (!file_exists($path)) {
            return null;
        }

        $slug = pathinfo($path, PATHINFO_FILENAME);

        // Проверка целостности: хэш файла должен совпадать с сохранённым
        $action = Action::where('slug', $slug)->first();
        if ($action && $action->code_hash) {
            $validator = app(ActionCodeValidator::class);
            $currentHash = $validator->hashFile($path);

            if (!hash_equals($action->code_hash, $currentHash)) {
                $logData = [
                    'slug' => $slug,
                    'path' => $path,
                    'expected_hash' => $action->code_hash,
                    'actual_hash' => $currentHash,
                ];

                try {
                    Log::channel('security')->critical('Action file integrity violation', $logData);
                } catch (\Throwable $e) {
                    Log::critical('Action file integrity violation', $logData);
                }

                // Файл был изменён вне CMS — отказываемся загружать
                return null;
            }
        }

        // Валидация кода через токенизатор перед загрузкой
        $code = file_get_contents($path);
        if ($code === false) {
            return null;
        }

        $validator = app(ActionCodeValidator::class);
        $errors = $validator->validate($code);

        if (!empty($errors)) {
            $logData = [
                'slug' => $slug,
                'path' => $path,
                'errors' => $errors,
            ];

            try {
                Log::channel('security')->warning('Action file failed validation', $logData);
            } catch (\Throwable $e) {
                Log::warning('Action file failed validation', $logData);
            }
            return null;
        }

        $class = require $path;

        if ($class instanceof BlockActionInterface) {
            return $class;
        }

        return null;
    }

    /**
     * Сканировать actions из app/Actions.
     */
    public function scanAppActions(): void
    {
        $path = app_path('Actions');

        if (!is_dir($path)) {
            return;
        }

        $files = glob($path . '/*.php');

        foreach ($files as $file) {
            $slug = pathinfo($file, PATHINFO_FILENAME);
            $slug = $this->toSlug($slug);

            // Определяем полное имя класса
            $className = 'App\\Actions\\' . pathinfo($file, PATHINFO_FILENAME);

            if (class_exists($className)) {
                $this->register($slug, $className, 'app');
            }
        }
    }

    /**
     * Сканировать actions из storage/cms/actions.
     */
    public function scanStorageActions(): void
    {
        $path = storage_path('cms/actions');

        if (!is_dir($path)) {
            return;
        }

        $files = glob($path . '/*.php');

        foreach ($files as $file) {
            $slug = pathinfo($file, PATHINFO_FILENAME);
            $this->register($slug, ['path' => $file, 'source' => 'storage'], 'storage');
        }
    }

    /**
     * Полное сканирование всех источников.
     */
    public function scan(): void
    {
        $this->scanAppActions();
        $this->scanStorageActions();
    }

    /**
     * Конвертировать CamelCase имя в slug.
     */
    protected function toSlug(string $name): string
    {
        return strtolower(preg_replace('/(?<!^)[A-Z]/', '-$0', $name));
    }
}
