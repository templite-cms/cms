<?php

namespace Templite\Cms\Services\Ai\Tools;

use Templite\Cms\Models\Block;
use Templite\Cms\Services\Ai\EditorContext;
use Templite\Cms\Services\BlockCodeVersionService;

class GetBlockCodeTool implements Tool
{
    public function __construct(private readonly BlockCodeVersionService $versions)
    {
    }

    public function name(): string
    {
        return 'get_block_code';
    }

    public function definition(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'block_id' => ['type' => 'integer', 'description' => 'ID типа блока из list_block_types'],
            ],
            'required' => ['block_id'],
        ];
    }

    public function execute(array $args, ?EditorContext $ctx): array
    {
        $blockId = (int) ($args['block_id'] ?? 0);
        $block = Block::with('fields:id,fieldable_type,fieldable_id,name,key,type,parent_id')->find($blockId);

        if (! $block) {
            return ['error' => 'block_not_found'];
        }

        // Открытый редактор этого блока держит несохранённый код — отдаём его.
        $live = $ctx && $ctx->isBlockEditorOpen($blockId) && $ctx->code;
        $code = $live ? $ctx->code : $this->versions->readFromDisk($block);

        return [
            'block' => ['id' => $block->id, 'slug' => $block->slug, 'name' => $block->name],
            'fields' => $block->fields->map(fn ($f) => [
                'name' => $f->name,
                'key' => $f->key,
                'type' => $f->type,
                'parent_id' => $f->parent_id,
            ])->all(),
            'template' => $code['template'] ?? '',
            'style' => $code['style'] ?? '',
            'script' => $code['script'] ?? '',
            'live' => (bool) $live,
        ];
    }
}
