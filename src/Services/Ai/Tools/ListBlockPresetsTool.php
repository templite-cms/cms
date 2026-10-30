<?php

namespace Templite\Cms\Services\Ai\Tools;

use Templite\Cms\Models\Block;
use Templite\Cms\Models\BlockPreset;
use Templite\Cms\Services\Ai\EditorContext;

class ListBlockPresetsTool implements Tool
{
    public function name(): string
    {
        return 'list_block_presets';
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
        $block = Block::find((int) ($args['block_id'] ?? 0));

        if (! $block) {
            return ['error' => 'block_not_found'];
        }

        $presets = BlockPreset::forBlock($block->id)
            ->withCount('pageBlocks')
            ->orderBy('order')
            ->get()
            ->map(fn (BlockPreset $p) => [
                'id' => $p->id,
                'name' => $p->name,
                'type' => $p->type,
                'is_default' => (bool) $p->is_default,
                'description' => $p->description,
                // Связи есть только у глобального: локальный копирует данные и забывается
                'linked_count' => $p->type === 'global' ? (int) $p->page_blocks_count : null,
            ])
            ->all();

        return [
            'block' => ['id' => $block->id, 'slug' => $block->slug, 'name' => $block->name],
            'presets' => $presets,
        ];
    }
}
