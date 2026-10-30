<?php

namespace Templite\Cms\Services\Ai\Tools;

use Templite\Cms\Models\Component;
use Templite\Cms\Services\Ai\EditorContext;
use Templite\Cms\Services\ComponentRegistry;

class GetComponentCodeTool implements Tool
{
    public function __construct(private readonly ComponentRegistry $registry)
    {
    }

    public function name(): string
    {
        return 'get_component_code';
    }

    public function definition(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'slug' => ['type' => 'string', 'description' => 'Slug компонента из list_components'],
            ],
            'required' => ['slug'],
        ];
    }

    public function execute(array $args, ?EditorContext $ctx): array
    {
        $slug = (string) ($args['slug'] ?? '');
        $entry = $this->registry->find($slug);

        if (!$entry) {
            return ['error' => 'component_not_found', 'slug' => $slug];
        }

        // Компонент из app или пакета лежит одним blade-файлом, storage —
        // каталогом с index.blade.php, стилями и скриптом.
        $path = is_array($entry) ? ($entry['path'] ?? null) : (is_string($entry) ? $entry : null);
        $source = is_array($entry) ? ($entry['source'] ?? null) : null;
        $row = Component::where('slug', $slug)->first();

        return array_filter([
            'slug' => $slug,
            'component_id' => $row?->id,
            'source' => $source,
            'editable' => $source === 'storage',
            'params' => $row?->params,
            'code' => $this->read($path),
        ], fn ($value) => $value !== null);
    }

    /** @return array<string,string> */
    private function read(?string $path): array
    {
        if (!$path) {
            return [];
        }

        if (is_file($path)) {
            return ['template' => (string) file_get_contents($path)];
        }

        $read = function (string $file) use ($path): string {
            $full = $path . '/' . $file;

            return is_file($full) ? (string) file_get_contents($full) : '';
        };

        return array_filter([
            'template' => $read('index.blade.php'),
            'style' => $read('style.scss') ?: $read('style.css'),
            'script' => $read('script.js'),
        ], fn ($value) => $value !== '');
    }
}
