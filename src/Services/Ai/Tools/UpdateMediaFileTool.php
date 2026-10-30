<?php

namespace Templite\Cms\Services\Ai\Tools;

use Templite\Cms\Models\File;
use Templite\Cms\Models\FileFolder;
use Templite\Cms\Services\Ai\EditorContext;

class UpdateMediaFileTool implements Tool
{
    public function name(): string
    {
        return 'update_media_file';
    }

    public function definition(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'file_id' => ['type' => 'integer'],
                'name' => ['type' => 'string', 'description' => 'Отображаемое имя файла'],
                'alt' => ['type' => 'string', 'description' => 'Альтернативный текст для изображений'],
                'title' => ['type' => 'string'],
                'folder_id' => ['type' => 'integer', 'description' => 'Переместить в папку; 0 — в корень'],
            ],
            'required' => ['file_id'],
        ];
    }

    public function execute(array $args, ?EditorContext $ctx): array
    {
        $file = File::find((int) ($args['file_id'] ?? 0));

        if (! $file) {
            return ['error' => 'file_not_found'];
        }

        // MediaController::update не принимает name, поэтому пишем в модель напрямую.
        $update = array_filter([
            'name' => $args['name'] ?? null,
            'alt' => $args['alt'] ?? null,
            'title' => $args['title'] ?? null,
        ], fn ($v) => $v !== null);

        if (array_key_exists('folder_id', $args)) {
            $folderId = (int) $args['folder_id'];

            if ($folderId && ! FileFolder::whereKey($folderId)->exists()) {
                return ['error' => 'folder_not_found'];
            }

            $update['folder_id'] = $folderId ?: null;
        }

        if ($update === []) {
            return ['error' => 'nothing_to_update'];
        }

        $file->update($update);

        return ['file_id' => $file->id, 'status' => 'updated'];
    }
}
