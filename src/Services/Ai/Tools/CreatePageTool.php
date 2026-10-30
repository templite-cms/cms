<?php

namespace Templite\Cms\Services\Ai\Tools;

use Illuminate\Http\Request;
use Templite\Cms\Http\Controllers\Api\PageController;
use Templite\Cms\Services\Ai\EditorContext;

class CreatePageTool implements Tool
{
    public function name(): string
    {
        return 'create_page';
    }

    public function definition(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'title' => ['type' => 'string'],
                'alias' => ['type' => 'string', 'description' => 'ЧПУ-сегмент (латиница)'],
                'parent_id' => ['type' => 'integer'],
            ],
            'required' => ['title', 'alias'],
        ];
    }

    public function execute(array $args, ?EditorContext $ctx): array
    {
        $request = Request::create('/api/cms/pages', 'POST', [
            'title' => $args['title'] ?? '',
            'alias' => $args['alias'] ?? '',
            'parent_id' => $args['parent_id'] ?? null,
            'status' => 1,
        ]);
        $request->setUserResolver(fn () => auth()->user());

        $response = app(PageController::class)->store($request);
        $payload = $response->getData(true);

        if (($payload['success'] ?? false) !== true) {
            return ['error' => 'create_failed', 'details' => $payload];
        }

        $page = $payload['data'] ?? [];

        return [
            'page_id' => $page['id'] ?? null,
            'url' => $page['url'] ?? null,
            // Заголовок и id едут вместе с адресом: в чате из голой ссылки
            // «Открыть страницу» не понять, какая из созданных страниц имеется
            // в виду, а по id панель собирает ещё и ссылку в редактор.
            'navigate' => [
                'page_id' => $page['id'] ?? null,
                'title' => $page['title'] ?? ($args['title'] ?? ''),
                'url' => $page['url'] ?? null,
            ],
        ];
    }
}
