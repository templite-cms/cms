<?php

namespace Templite\Cms\Http\Controllers\Web;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Session\TokenMismatchException;
use Templite\Cms\Contracts\ActionContext;
use Templite\Cms\Models\Action;
use Templite\Cms\Models\Page;
use Templite\Cms\Services\ActionRegistry;
use Templite\Cms\Services\ActionRunner;

/**
 * Контроллер для прямого HTTP-вызова отдельного Action по slug.
 *
 * POST|GET /action/{actionSlug}
 * Требует allow_http = true у действия.
 * CSRF проверяется per-action через csrfEnabled() в классе действия.
 */
class DirectActionController extends Controller
{
    public function __construct(
        protected ActionRunner $actionRunner,
        protected ActionRegistry $actionRegistry,
    ) {}

    public function handle(Request $request, string $actionSlug): JsonResponse
    {
        $action = Action::where('slug', $actionSlug)->firstOrFail();

        if (!$action->allow_http) {
            return response()->json([
                'success' => false,
                'message' => 'Это действие недоступно по HTTP.',
            ], 403);
        }

        // CSRF: проверяем если action-класс этого требует (по умолчанию — да)
        if ($request->isMethod('POST')) {
            $instance = $this->actionRegistry->resolve($actionSlug);
            $csrfRequired = $instance ? $instance->csrfEnabled() : true;

            if ($csrfRequired) {
                $sessionToken = $request->session()->token();
                $requestToken = $request->input('_token') ?: $request->header('X-CSRF-TOKEN');

                if (!$sessionToken || !$requestToken || !hash_equals($sessionToken, $requestToken)) {
                    return response()->json([
                        'success' => false,
                        'message' => 'CSRF token mismatch.',
                    ], 419);
                }
            }
        }

        $pageId = $request->input('_page_id');
        $page = $pageId ? Page::find($pageId) : new Page();

        $params = $request->except(['_token', '_page_id', '_hp_name', '_hp_time']);

        $context = new ActionContext(
            page: $page ?? new Page(),
            request: $request,
            global: app()->bound('global_fields') ? app('global_fields') : [],
            blockData: [],
        );

        try {
            $result = $this->actionRunner->runSingle($actionSlug, $params, $context->page, $request, $context->global);

            return response()->json([
                'success' => true,
                'data' => $result,
            ]);
        } catch (\Throwable $e) {
            \Log::error('Direct action error', [
                'action' => $actionSlug,
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
