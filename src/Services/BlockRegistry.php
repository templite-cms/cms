<?php

namespace Templite\Cms\Services;

use Illuminate\Support\Collection;
use Templite\Cms\Contracts\RegistryInterface;

class BlockRegistry implements RegistryInterface
{
    /**
     * Реестр блоков: [source => [slug => entity]]
     */
    protected array $registry = [
        'app' => [],
        'storage' => [],
        'vendor' => [],
    ];

    /**
     * Приоритет источников (от высшего к низшему).
     */
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

        // Обходим в обратном порядке приоритета, чтобы высший перезаписал низший
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
     * Сканировать директорию app/Blocks на наличие блоков.
     */
    public function scanAppBlocks(): void
    {
        $path = app_path('Blocks');

        if (!is_dir($path)) {
            return;
        }

        $directories = glob($path . '/*', GLOB_ONLYDIR);

        foreach ($directories as $dir) {
            $slug = basename($dir);
            $this->register($slug, [
                'slug' => $slug,
                'path' => $dir,
                'source' => 'app',
            ], 'app');
        }
    }

    /**
     * Сканировать директорию storage/cms/blocks.
     */
    public function scanStorageBlocks(): void
    {
        $path = storage_path('cms/blocks');

        if (!is_dir($path)) {
            return;
        }

        $directories = glob($path . '/*', GLOB_ONLYDIR);

        foreach ($directories as $dir) {
            $slug = basename($dir);
            $this->register($slug, [
                'slug' => $slug,
                'path' => $dir,
                'source' => 'storage',
            ], 'storage');
        }
    }

    /**
     * Сканировать блоки из vendor пакетов.
     */
    public function scanVendorBlocks(): void
    {
        // Vendor-блоки регистрируются через ServiceProvider
    }

    /**
     * Полное сканирование всех источников.
     */
    public function scan(): void
    {
        $this->scanAppBlocks();
        $this->scanStorageBlocks();
        $this->scanVendorBlocks();
    }
}
