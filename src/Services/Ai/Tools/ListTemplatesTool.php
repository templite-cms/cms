<?php

namespace Templite\Cms\Services\Ai\Tools;

use Templite\Cms\Models\TemplatePage;
use Templite\Cms\Services\Ai\EditorContext;

class ListTemplatesTool implements Tool
{
    public function name(): string
    {
        return 'list_templates';
    }

    public function definition(): array
    {
        return ['type' => 'object', 'properties' => new \stdClass()];
    }

    public function execute(array $args, ?EditorContext $ctx): array
    {
        $templates = TemplatePage::query()
            ->with('libraries:id,slug,name')
            ->orderBy('name')
            ->get(['id', 'name', 'slug']);

        return [
            'templates' => $templates->map(fn (TemplatePage $template) => [
                'id' => $template->id,
                'slug' => $template->slug,
                'name' => $template->name,
                // Набор показываем сразу: attach_library работает точечно и
                // текущего состава не возвращает, а без него агент не сможет
                // ответить на «что уже подключено к шаблону»
                'libraries' => $template->libraries->map(fn ($library) => [
                    'id' => $library->id,
                    'slug' => $library->slug,
                ])->all(),
            ])->all(),
        ];
    }
}
