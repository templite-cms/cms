<?php

namespace Templite\Cms\Services\Ai\Tools;

use Illuminate\Http\Request;
use Templite\Cms\Http\Controllers\Api\PageBlockController;
use Templite\Cms\Models\Action;
use Templite\Cms\Models\BlockAction;
use Templite\Cms\Models\PageBlock;
use Templite\Cms\Services\Ai\EditorContext;

/**
 * Параметры action для одного экземпляра блока на странице.
 *
 * Правка точечная: в page_blocks.action_params лежат настройки всех actions
 * блока разом, и запись целиком стёрла бы соседние. Ключ — id action, именно
 * его читает ActionRunner.
 */
class SetActionParamsTool implements Tool
{
    public function name(): string
    {
        return 'set_action_params';
    }

    public function definition(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'page_block_id' => ['type' => 'integer', 'description' => 'ID блока на странице из get_page (page_block_id, не block_id)'],
                'action_id' => ['type' => 'integer', 'description' => 'ID action из list_actions'],
                'params' => ['type' => 'object', 'description' => 'Параметры именно для этого блока на этой странице: перекрывают параметры привязки'],
            ],
            'required' => ['page_block_id', 'action_id', 'params'],
        ];
    }

    public function execute(array $args, ?EditorContext $ctx): array
    {
        $pageBlockId = (int) ($args['page_block_id'] ?? 0);
        $actionId = (int) ($args['action_id'] ?? 0);
        $params = is_array($args['params'] ?? null) ? $args['params'] : [];

        $pb = PageBlock::find($pageBlockId);

        if (!$pb) {
            return ['error' => 'page_block_not_found', 'page_block_id' => $pageBlockId];
        }

        if (!Action::whereKey($actionId)->exists()) {
            return ['error' => 'action_not_found', 'action_id' => $actionId];
        }

        // Параметры экземпляра работают поверх привязки: без неё ActionRunner
        // даже не дойдёт до этого action, и настройка осталась бы мёртвой
        $attached = BlockAction::where('block_id', $pb->block_id)
            ->where('action_id', $actionId)
            ->exists();

        if (!$attached) {
            return [
                'error' => 'action_not_attached',
                'block_id' => $pb->block_id,
                'message' => 'Сначала подключи action к типу блока через attach_action.',
            ];
        }

        $merged = $pb->action_params ?? [];
        $merged[$actionId] = array_merge($merged[$actionId] ?? [], $params);

        // Редактор страницы держит блоки в форме и сохраняет их пачкой:
        // запись в базу мимо него пережила бы ровно до нажатия «Сохранить»
        if ($ctx && $ctx->isOpen() && $pb->page_id === $ctx->pageId) {
            return [
                'status' => 'proposed_to_editor',
                'editor_op' => [
                    'op' => 'update_action_params',
                    'payload' => ['page_block_id' => $pageBlockId, 'action_params' => $merged],
                ],
            ];
        }

        // Через контроллер, а не $pb->save(): он заводит версию в истории и
        // сбрасывает кэш блока — прямая запись оставила бы старый HTML
        $request = Request::create("/api/cms/page-blocks/{$pageBlockId}", 'PUT', ['action_params' => $merged]);
        $request->setUserResolver(fn () => auth()->user());

        $response = app(PageBlockController::class)->update($request, $pageBlockId);
        $payload = $response->getData(true);

        if (($payload['success'] ?? false) !== true) {
            return ['error' => 'save_failed', 'message' => $payload['message'] ?? null];
        }

        return [
            'status' => 'saved',
            'page_block_id' => $pageBlockId,
            'action_params' => $merged,
        ];
    }
}
