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
     * Сканировать vendor blade-компоненты из пакета CMS.
     * Путь: packages/templite/cms/resources/views/components/*.blade.php
     */
    public function scanVendorComponents(): void
    {
        $path = dirname(__DIR__, 2) . '/resources/views/components';

        if (!is_dir($path)) {
            return;
        }

        $files = glob($path . '/*.blade.php');

        foreach ($files as $file) {
            $slug = basename($file, '.blade.php');
            $this->register($slug, [
                'slug' => $slug,
                'path' => $file,
                'source' => 'vendor',
            ], 'vendor');
        }
    }

    /**
     * Полное сканирование.
     */
    public function scan(): void
    {
        $this->scanAppComponents();
        $this->scanStorageComponents();
        $this->scanVendorComponents();
    }

    /**
     * Получить справочник Blade-компонентов для фронтенда.
     *
     * Возвращает массив с тегом, описанием и props каждого компонента,
     * отсортированный по source (vendor -> storage -> app), затем по slug.
     */
    public function getBladeComponentReference(): array
    {
        $this->scan();
        $reference = [];
        $allComponents = $this->all();

        foreach ($allComponents as $slug => $entry) {
            $filePath = $entry['path'] ?? null;
            // Storage components store directory path, resolve to index.blade.php
            if ($filePath && is_dir($filePath)) {
                $filePath = $filePath . '/index.blade.php';
            }

            if (!$filePath || !file_exists($filePath)) {
                $reference[] = [
                    'slug' => $slug,
                    'tag' => '<x-cms::' . $slug . ' />',
                    'description' => '',
                    'props' => [],
                    'source' => $entry['source'] ?? 'unknown',
                ];
                continue;
            }

            $content = file_get_contents($filePath);
            $parsed = $this->parseBladeDocblock($content);

            $reference[] = [
                'slug' => $slug,
                'tag' => '<x-cms::' . $slug . ' />',
                'description' => $parsed['description'],
                'props' => $parsed['props'],
                'source' => $entry['source'] ?? 'unknown',
                'code' => $content,
            ];
        }

        usort($reference, fn ($a, $b) =>
            ($a['source'] === $b['source'])
                ? strcmp($a['slug'], $b['slug'])
                : array_search($a['source'], ['vendor', 'storage', 'app']) <=> array_search($b['source'], ['vendor', 'storage', 'app'])
        );

        return $reference;
    }

    /**
     * Парсинг Blade docblock-комментария и @props директивы.
     */
    protected function parseBladeDocblock(string $content): array
    {
        $description = '';
        $props = [];

        // Parse docblock: {{-- ... --}}
        if (preg_match('/\{\{--\s*(.*?)--\}\}/s', $content, $match)) {
            $docblock = trim($match[1]);
            $lines = array_map('trim', explode("\n", $docblock));

            foreach ($lines as $line) {
                if (!empty($line)
                    && !str_starts_with($line, 'Компонент:')
                    && !str_starts_with($line, 'TASK-')
                    && !str_starts_with($line, 'Использование')
                    && !str_starts_with($line, 'Параметры:')
                ) {
                    $description = $line;
                    break;
                }
            }
        }

        // Parse @props(['key' => 'default', ...])
        if (preg_match("/@props\(\[(.*?)\]\)/s", $content, $match)) {
            $propsStr = $match[1];
            preg_match_all("/['\"](\w+)['\"]\s*(?:=>\s*(.+?))?(?:,|$)/", $propsStr, $propMatches, PREG_SET_ORDER);

            foreach ($propMatches as $pm) {
                $props[] = [
                    'name' => $pm[1],
                    'default' => isset($pm[2]) ? trim($pm[2], " \t\n\r\0\x0B'\"") : null,
                ];
            }
        }

        return ['description' => $description, 'props' => $props];
    }

    /**
     * Конвертировать CamelCase имя в slug.
     */
    protected function toSlug(string $name): string
    {
        return strtolower(preg_replace('/(?<!^)[A-Z]/', '-$0', $name));
    }
}
