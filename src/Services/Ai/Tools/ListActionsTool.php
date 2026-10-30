<?php

namespace Templite\Cms\Services\Ai\Tools;

use Templite\Cms\Models\Action;
use Templite\Cms\Services\Ai\EditorContext;

class ListActionsTool implements Tool
{
    public function name(): string
    {
        return 'list_actions';
    }

    public function definition(): array
    {
        return ['type' => 'object', 'properties' => new \stdClass()];
    }

    public function execute(array $args, ?EditorContext $ctx): array
    {
        $actions = Action::query()
            ->with('blockActions.block:id,slug')
            ->orderBy('name')
            ->get();

        return [
            'actions' => $actions->map(fn (Action $action) => [
                'id' => $action->id,
                'slug' => $action->slug,
                'name' => $action->name,
                'description' => $action->description,
                // Реестр ищет в порядке app > storage > vendor, а запись идёт
                // всегда в storage: у app и vendor правка не подействовала бы
                'source' => $action->source,
                'editable' => $action->source === 'storage',
                'params' => $action->params,
                'returns' => $action->returns,
                'allow_http' => (bool) $action->allow_http,
                // Без этого списка агент не ответит, где action уже работает,
                // и заведёт вторую копию того же самого
                'attached_to_blocks' => $action->blockActions
                    ->map(fn ($ba) => $ba->block?->slug)
                    ->filter()
                    ->values()
                    ->all(),
            ])->all(),
        ];
    }
}
