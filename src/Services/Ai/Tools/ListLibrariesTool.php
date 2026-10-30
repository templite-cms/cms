<?php

namespace Templite\Cms\Services\Ai\Tools;

use Templite\Cms\Models\Library;
use Templite\Cms\Services\Ai\EditorContext;

class ListLibrariesTool implements Tool
{
    public function name(): string
    {
        return 'list_libraries';
    }

    public function definition(): array
    {
        return ['type' => 'object', 'properties' => new \stdClass()];
    }

    public function execute(array $args, ?EditorContext $ctx): array
    {
        $libraries = Library::query()
            ->with('files')
            ->withCount(['blocks', 'templatePages'])
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        return [
            'libraries' => $libraries->map(fn (Library $library) => [
                'id' => $library->id,
                'slug' => $library->slug,
                'name' => $library->name,
                // Неактивная библиотека не попадает в теги подключения:
                // без этого флага агент привязал бы её и отчитался об успехе,
                // а на сайте ничего бы не изменилось
                'active' => (bool) $library->active,
                'files' => $library->files->map(fn ($file) => [
                    'type' => $file->type,
                    'source' => $file->source,
                    'version' => $file->version,
                ])->all(),
                // Чтобы агент не заводил вторую копию уже подключённой
                // библиотеки и видел, что она где-то используется
                'attached_to' => [
                    'blocks' => $library->blocks_count,
                    'templates' => $library->template_pages_count,
                ],
            ])->all(),
        ];
    }
}
