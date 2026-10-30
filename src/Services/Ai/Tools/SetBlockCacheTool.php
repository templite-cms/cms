<?php

namespace Templite\Cms\Services\Ai\Tools;

use Illuminate\Http\Request;
use Templite\Cms\Http\Controllers\Api\PageBlockController;
use Templite\Cms\Models\PageBlock;
use Templite\Cms\Services\Ai\EditorContext;

/**
 * Кэш HTML одного блока на странице.
 *
 * Блок с подключённым action закэшированным держать нельзя: результат action
 * посчитается один раз, и все посетители увидят его вместо живых данных.
 * Решает агент — движок кэш сам не отключает.
 */
class SetBlockCacheTool implements Tool
{
    public function name(): string
    {
        return 'set_block_cache';
    }

    public function definition(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'page_block_id' => ['type' => 'integer', 'description' => 'ID блока на странице из get_page (page_block_id, не block_id)'],
                'enabled' => ['type' => 'boolean', 'description' => 'true — кэшировать HTML блока, false — рендерить при каждом запросе'],
            ],
            'required' => ['page_block_id', 'enabled'],
        ];
    }

    public function execute(array $args, ?EditorContext $ctx): array
    {
        $pageBlockId = (int) ($args['page_block_id'] ?? 0);
        $enabled = (bool) ($args['enabled'] ?? false);

        $pb = PageBlock::with('block.blockActions:id,block_id')->find($pageBlockId);

        if (!$pb) {
            return ['error' => 'page_block_not_found', 'page_block_id' => $pageBlockId];
        }

        $hasActions = $pb->block && $pb->block->blockActions->isNotEmpty();

        // Редактор страницы сохраняет флаг кэша вместе с блоком: запись в базу
        // мимо формы пережила бы ровно до нажатия «Сохранить»
        if ($ctx && $ctx->isOpen() && $pb->page_id === $ctx->pageId) {
            return [
                'status' => 'proposed_to_editor',
                'editor_op' => [
                    'op' => 'set_block_cache',
                    'payload' => ['page_block_id' => $pageBlockId, 'cache_enabled' => $enabled],
                ],
            ];
        }

        // Через контроллер: при выключении он заодно сбрасывает уже
        // закэшированный HTML и пишет действие в журнал
        $request = Request::create("/api/cms/page-blocks/{$pageBlockId}/toggle-cache", 'PUT', ['cache_enabled' => $enabled]);
        $request->setUserResolver(fn () => auth()->user());

        $payload = app(PageBlockController::class)->toggleCache($request, $pageBlockId)->getData(true);

        if (($payload['success'] ?? false) !== true) {
            return ['error' => 'save_failed', 'message' => $payload['message'] ?? null];
        }

        return array_filter([
            'status' => 'saved',
            'page_block_id' => $pageBlockId,
            'cache_enabled' => $enabled,
            'warning' => $enabled && $hasActions
                ? 'К типу этого блока подключён action — с кэшем посетители увидят один и тот же закэшированный результат вместо живых данных.'
                : null,
        ], fn ($value) => $value !== null);
    }
}
