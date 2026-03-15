<?php

namespace Templite\Cms\Services;

use Illuminate\Support\Collection;
use Templite\Cms\Contracts\RegistryInterface;

class ComponentRegistry implements RegistryInterface
{
    /**
     * Реестр компонентов: [source => [slug => data]]
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
     * Сканировать компоненты из app/View/Components/Cms.
     */
    public function scanAppComponents(): void
    {
        $path = app_path('View/Components/Cms');

        if (!is_dir($path)) {
            return;
        }

        $files = glob($path . '/*.php');

        foreach ($files as $file) {
            $name = pathinfo($file, PATHINFO_FILENAME);
            $slug = $this->toSlug($name);
            $className = 'App\\View\\Components\\Cms\\' . $name;

            $this->register($slug, [
                'slug' => $slug,
                'class' => $className,
                'source' => 'app',
            ], 'app');
        }
    }

    /**
     * Сканировать компоненты из storage/cms/components.
     * Каждый компонент — директория с index.blade.php.
     */
    public function scanStorageComponents(): void
    {
        $path = storage_path('cms/components');

        if (!is_dir($path)) {
            return;
        }

        $dirs = glob($path . '/*', GLOB_ONLYDIR);

        foreach ($dirs as $dir) {
            $indexFile = $dir . '/index.blade.php';
            if (file_exists($indexFile)) {
                $slug = basename($dir);
                $this->register($slug, [
                    'slug' => $slug,
                    'path' => $dir,
                    'source' => 'storage',
                ], 'storage');
            }
        }
    }

    /**
     * Полное сканирование.
     */
    public function scan(): void
    {
        $this->scanAppComponents();
        $this->scanStorageComponents();
    }

    /**
     * Конвертировать CamelCase имя в slug.
     */
    protected function toSlug(string $name): string
    {
        return strtolower(preg_replace('/(?<!^)[A-Z]/', '-$0', $name));
    }
}
