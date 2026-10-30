<?php

namespace Templite\Cms\Services\Ai\Tools;

use Templite\Cms\Services\Ai\EditorContext;
use Templite\Cms\Services\FileService;

class ListMediaTool implements Tool
{
    /** Больше агенту не нужно: список уходит в контекст модели. */
    private const MAX_LIMIT = 50;

    public function __construct(private readonly FileService $files)
    {
    }

    public function name(): string
    {
        return 'list_media';
    }

    public function definition(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'search' => ['type' => 'string', 'description' => 'Поиск по имени файла'],
                'type' => ['type' => 'string', 'description' => 'image, video, audio, document, archive, other'],
                'folder_id' => ['type' => 'integer', 'description' => 'ID папки из list_media_folders'],
                'limit' => ['type' => 'integer', 'description' => 'До 50, по умолчанию 20'],
            ],
        ];
    }

    public function execute(array $args, ?EditorContext $ctx): array
    {
        $limit = min((int) ($args['limit'] ?? 20) ?: 20, self::MAX_LIMIT);

        $filters = array_filter([
            'search' => $args['search'] ?? null,
            'type' => $args['type'] ?? null,
            'folder_id' => $args['folder_id'] ?? null,
        ], fn ($v) => $v !== null && $v !== '');

        $paginator = $this->files->getFiles($filters, $limit);

        return [
            'total' => $paginator->total(),
            'files' => collect($paginator->items())->map(fn ($f) => [
                'id' => $f->id,
                'name' => $f->name,
                'type' => $f->type,
                'mime' => $f->mime,
                'size' => $f->size,
                'folder_id' => $f->folder_id,
                'alt' => $f->alt,
            ])->all(),
        ];
    }
}
