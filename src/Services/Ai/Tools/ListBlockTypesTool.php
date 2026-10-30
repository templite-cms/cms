<?php

namespace Templite\Cms\Services\Ai\Tools;

use Templite\Cms\Models\Block;
use Templite\Cms\Services\Ai\EditorContext;

class ListBlockTypesTool implements Tool
{
    public function name(): string
    {
        return 'list_block_types';
    }

    public function definition(): array
    {
        return ['type' => 'object', 'properties' => new \stdClass()];
    }

    public function execute(array $args, ?EditorContext $ctx): array
    {
        $blocks = Block::query()
            ->with(['fields:id,fieldable_type,fieldable_id,name,key,type,parent_id', 'libraries:id,slug'])
            ->orderBy('name')
            ->get();

        return [
            'block_types' => $blocks->map(fn (Block $b) => [
                'id' => $b->id,
                'slug' => $b->slug,
                'name' => $b->name,
                'category_id' => $b->block_type_id,
                'fields' => $b->fields->map(fn ($f) => [
                    'name' => $f->name,
                    'key' => $f->key,
                    'type' => $f->type,
                    'parent_id' => $f->parent_id,
                ])->all(),
                // attach_library правит набор точечно и текущего состава не
                // возвращает: без этого списка агент не ответит, что к блоку
                // уже подключено, и не поймёт, чем пользуется чужая вёрстка
                'libraries' => $b->libraries->map(fn ($l) => [
                    'id' => $l->id,
                    'slug' => $l->slug,
                ])->all(),
            ])->all(),
            // Категории нужны для create_block_type: без них агент угадывает
            // block_type_id и тратит шаги петли на неудачные попытки.
            'categories' => \Templite\Cms\Models\BlockType::orderBy('order')
                ->get(['id', 'slug', 'name'])
                ->map(fn ($t) => ['id' => $t->id, 'slug' => $t->slug, 'name' => $t->name])
                ->all(),
        ];
    }
}
