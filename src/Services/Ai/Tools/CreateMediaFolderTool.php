<?php

namespace Templite\Cms\Services\Ai\Tools;

use Templite\Cms\Models\FileFolder;
use Templite\Cms\Services\Ai\EditorContext;
use Templite\Cms\Services\FileService;

class CreateMediaFolderTool implements Tool
{
    public function __construct(private readonly FileService $files)
    {
    }

    public function name(): string
    {
        return 'create_media_folder';
    }

    public function definition(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'name' => ['type' => 'string'],
                'parent_id' => ['type' => 'integer', 'description' => 'Родительская папка; без неё — в корне'],
            ],
            'required' => ['name'],
        ];
    }

    public function execute(array $args, ?EditorContext $ctx): array
    {
        $name = trim((string) ($args['name'] ?? ''));

        if ($name === '') {
            return ['error' => 'invalid_name'];
        }

        $parentId = $args['parent_id'] ?? null;

        if ($parentId !== null && ! FileFolder::whereKey($parentId)->exists()) {
            return ['error' => 'parent_folder_not_found'];
        }

        $folder = $this->files->createFolder($name, $parentId ? (int) $parentId : null);

        return ['folder_id' => $folder->id, 'name' => $folder->name];
    }
}
