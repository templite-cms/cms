<?php

namespace Templite\Cms\Services\Ai\Tools;

use Templite\Cms\Models\Component;
use Templite\Cms\Services\Ai\EditorContext;
use Templite\Cms\Services\ComponentRegistry;

/**
 * Blade-компоненты: переиспользуемые куски вёрстки для блоков и шаблонов.
 *
 * Список берётся из реестра, а не из таблицы: компонент работает, пока на
 * диске лежит его index.blade.php, запись в базе нужна только админке.
 * По таблице агент увидел бы пустоту там, где компоненты есть.
 */
class ListComponentsTool implements Tool
{
    public function __construct(private readonly ComponentRegistry $registry)
    {
    }

    public function name(): string
    {
        return 'list_components';
    }

    public function definition(): array
    {
        return ['type' => 'object', 'properties' => new \stdClass()];
    }

    public function execute(array $args, ?EditorContext $ctx): array
    {
        // Параметры и описание живут в базе, файлы — на диске: сводим вместе.
        $meta = Component::get(['id', 'slug', 'name', 'description', 'params'])->keyBy('slug');

        $components = collect($this->registry->all())
            ->map(function ($entry, $slug) use ($meta) {
                $row = $meta->get($slug);

                return array_filter([
                    'slug' => $slug,
                    'tag' => "<x-cms::{$slug} />",
                    'name' => $row?->name,
                    'description' => $row?->description,
                    'params' => $row?->params,
                    'source' => is_array($entry) ? ($entry['source'] ?? null) : null,
                    // Код правится только у тех, чьи файлы лежат в storage:
                    // app и vendor перекрывают его по приоритету реестра.
                    'editable' => (is_array($entry) ? ($entry['source'] ?? null) : null) === 'storage',
                    'component_id' => $row?->id,
                ], fn ($value) => $value !== null);
            })
            ->values()
            ->all();

        return [
            'components' => $components,
            'usage' => 'Вставляй компонент в Blade блока или шаблона тегом <x-cms::slug />. Его стили и скрипты подключатся к странице сами — руками ничего добавлять не нужно.',
        ];
    }
}
