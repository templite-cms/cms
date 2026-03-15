<?php

namespace Templite\Cms\Http\Controllers\Web;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Templite\Cms\Contracts\ActionContext;
use Templite\Cms\Models\Block;
use Templite\Cms\Models\Page;
use Templite\Cms\Models\PageBlock;
use Templite\Cms\Services\ActionRunner;

/**
 * Контроллер для обработки POST-запросов actions блоков.
 *
 * Принимает данные форм и выполняет привязанные actions.
 * Используется для обработки форм обратной связи, подписок, и т.д.
 */
class BlockActionController extends Controller
{
    public function __construct(protected ActionRunner $actionRunner) {}

    /**
     * Выполнение actions блока.
     * POST /cms/block-action/{pageBlockId}
     */
    public function handle(Request $request, int $pageBlockId): JsonResponse
    {
        $pageBlock = PageBlock::with(['block.blockActions.action', 'page'])
            ->findOrFail($pageBlockId);

        if (!$pageBlock->page || !$pageBlock->page->is_published) {
            return response()->json([
                'success' => false,
                'message' => 'Страница не найдена.',
            ], 404);
        }

        // Формируем контекст
        $context = new ActionContext(
            page: $pageBlock->page,
            request: $request,
            global: app()->bound('global_fields') ? app('global_fields') : [],
            blockData: $pageBlock->data ?? [],
        );

        try {
            $results = $this->actionRunner->run($pageBlock->block, $context);

            return response()->json([
                'success' => true,
                'data' => $results,
            ]);
        } catch (\Throwable $e) {
            \Log::error('Block action error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Произошла ошибка при выполнении действия.',
            ], 500);
        }
    }

    /**
     * Выполнение actions блока по slug.
     * POST /cms/action/{blockSlug}
     */
    public function handleBySlug(Request $request, string $blockSlug): JsonResponse
    {
        $block = Block::where('slug', $blockSlug)
            ->with('blockActions.action')
            ->firstOrFail();

        // Ищем страницу из referer или request
        $pageId = $request->input('_page_id');
        $page = $pageId ? Page::find($pageId) : null;

        // TASK-S01: Данные блока формируются на сервере, а не из запроса.
        // Запрещаем передачу _block_data из внешних запросов для предотвращения
        // инъекции произвольных данных в контекст выполнения actions.
        $blockData = [];
        if ($page) {
            $pageBlock = PageBlock::where('page_id', $page->id)
                ->where('block_id', $block->id)
                ->first();
            $blockData = $pageBlock?->data ?? [];
        }

        $context = new ActionContext(
            page: $page ?? new Page(),
            request: $request,
            global: app()->bound('global_fields') ? app('global_fields') : [],
            blockData: $blockData,
        );

        try {
            $results = $this->actionRunner->run($block, $context);

            return response()->json([
                'success' => true,
                'data' => $results,
            ]);
        } catch (\Throwable $e) {
            \Log::error('Block action error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Произошла ошибка при выполнении действия.',
            ], 500);
        }
    }
}
