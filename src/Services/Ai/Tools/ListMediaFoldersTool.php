<?php

namespace Templite\Cms\Services\Ai\Tools;

use Templite\Cms\Models\FileFolder;
use Templite\Cms\Services\Ai\EditorContext;

class ListMediaFoldersTool implements Tool
{
    public function name(): string
    {
        return 'list_media_folders';
    }

    public function definition(): array
    {
        return ['type' => 'object', 'properties' => new \stdClass()];
    }

    public function execute(array $args, ?EditorContext $ctx): array
    {
        return [
            'folders' => FileFolder::orderBy('name')
                ->get(['id', 'name', 'parent_id'])
                ->map(fn (FileFolder $f) => [
                    'id' => $f->id,
                    'name' => $f->name,
                    'parent_id' => $f->parent_id,
                ])->all(),
        ];
    }
}
