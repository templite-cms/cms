<?php

namespace Templite\Cms\Services\Ai\Tools;

use Templite\Cms\Models\Page;
use Templite\Cms\Services\Ai\EditorContext;

class ListPagesTool implements Tool
{
    public function name(): string
    {
        return 'list_pages';
    }

    public function definition(): array
    {
        return [
            'type' => 'object',
            'properties' => new \stdClass(),
        ];
    }

    public function execute(array $args, ?EditorContext $ctx): array
    {
        $pages = Page::query()->orderBy('id')->limit(200)->get(['id', 'title', 'alias', 'url', 'status']);

        return ['pages' => $pages->map(fn (Page $p) => [
            'id' => $p->id,
            'title' => $p->title,
            'url' => $p->url,
            'status' => $p->status,
        ])->all()];
    }
}
