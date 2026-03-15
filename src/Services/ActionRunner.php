<?php

namespace Templite\Cms\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Templite\Cms\Contracts\ActionContext;
use Templite\Cms\Contracts\BlockActionInterface;
use Templite\Cms\Models\Block;
use Templite\Cms\Models\Page;

class ActionRunner
{
    public function __construct(
        protected ActionRegistry $actionRegistry
    ) {}

    /**
     * Выполнить все actions блока и вернуть объединённый результат.
     *
     * @param  array  $pageActionParams  Page-level param overrides keyed by action_id: [action_id => [key => value]]
     */
    public function run(
        Block $block,
        array $resolvedData,
        Page $page,
        Request $request,
        array $global,
        array $pageActionParams = []
    ): array {
        $result = [];

        // Используем eager-loaded данные если доступны, иначе делаем запрос (fallback для runSingle и т.д.)
        $blockActions = $block->relationLoaded('blockActions')
            ? $block->blockActions->sortBy('order')->values()
            : $block->blockActions()->with('action')->orderBy('order')->get();

        $context = new ActionContext(
            page: $page,
            request: $request,
            global: $global,
            blockData: $resolvedData,
        );

        foreach ($blockActions as $blockAction) {
            $action = $blockAction->action;

            if (!$action) {
                continue;
            }

            try {
                $actionInstance = $this->resolveAction($action);

                if (!$actionInstance) {
                    Log::warning("CMS: Action '{$action->slug}' не найден в реестре.");
                    continue;
                }

                // Мерж параметров: defaults → block-level → page-level
                $params = array_merge(
                    $this->getDefaultParams($actionInstance),
                    $blockAction->params ?? [],
                    $pageActionParams[$action->id] ?? []
                );

                $actionResult = $actionInstance->handle($params, $context);

                // Группируем по slug — каждый action в своём namespace
                $result[$action->slug] = $actionResult;
            } catch (\Throwable $e) {
                Log::error("CMS: Ошибка выполнения action '{$action->slug}': {$e->getMessage()}", [
                    'action' => $action->slug,
                    'block' => $block->slug,
                    'page' => $page->url,
                    'exception' => $e,
                ]);
            }
        }

        return $result;
    }

    /**
     * Выполнить один action (для тестирования из админки).
     */
    public function runSingle(
        string $actionSlug,
        array $params,
        Page $page,
        Request $request,
        array $global = [],
        array $blockData = []
    ): array {
        $actionInstance = $this->actionRegistry->resolve($actionSlug);

        if (!$actionInstance) {
            throw new \RuntimeException("Action '{$actionSlug}' не найден.");
        }

        $context = new ActionContext(
            page: $page,
            request: $request,
            global: $global,
            blockData: $blockData,
        );

        return $actionInstance->handle($params, $context);
    }

    /**
     * Резолвить action: сначала из реестра, потом из БД.
     */
    protected function resolveAction($action): ?BlockActionInterface
    {
        // Сначала пробуем найти в реестре по slug
        $instance = $this->actionRegistry->resolve($action->slug);

        if ($instance) {
            return $instance;
        }

        // Пробуем загрузить из class_name
        if ($action->class_name && class_exists($action->class_name)) {
            $instance = app($action->class_name);
            if ($instance instanceof BlockActionInterface) {
                return $instance;
            }
        }

        return null;
    }

    /**
     * Получить значения параметров по умолчанию из определения action.
     */
    protected function getDefaultParams(BlockActionInterface $action): array
    {
        $defaults = [];

        foreach ($action->params() as $key => $config) {
            if (isset($config['default'])) {
                $defaults[$key] = $config['default'];
            }
        }

        return $defaults;
    }
}
