<?php

namespace Templite\Cms\Services\Ai\Tools;

use Templite\Cms\Models\Action;
use Templite\Cms\Models\Block;
use Templite\Cms\Models\BlockAction;
use Templite\Cms\Models\PageBlock;
use Templite\Cms\Services\Ai\EditorContext;

/**
 * Подключение action к типу блока — и отключение обратно.
 *
 * Привязка ищется по паре block+action, а не создаётся вслепую: уникального
 * индекса на этой паре нет, и второй POST завёл бы дубль, из-за которого
 * ActionRunner выполнил бы action дважды за рендер.
 */
class AttachActionTool implements Tool
{
    public function name(): string
    {
        return 'attach_action';
    }

    public function definition(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'action_id' => ['type' => 'integer', 'description' => 'ID action из list_actions'],
                'block_id' => ['type' => 'integer', 'description' => 'ID типа блока из list_block_types'],
                'action' => [
                    'type' => 'string',
                    'enum' => ['attach', 'detach'],
                    'description' => 'attach — подключить (по умолчанию), detach — отключить',
                ],
                'params' => ['type' => 'object', 'description' => 'Параметры для этого типа блока: перекрывают значения default из params()'],
                'order' => ['type' => 'integer', 'description' => 'Порядок выполнения среди actions блока. Без него — в конец'],
            ],
            'required' => ['action_id', 'block_id'],
        ];
    }

    public function execute(array $args, ?EditorContext $ctx): array
    {
        $actionId = (int) ($args['action_id'] ?? 0);
        $blockId = (int) ($args['block_id'] ?? 0);

        $action = Action::find($actionId);

        if (!$action) {
            return ['error' => 'action_not_found', 'action_id' => $actionId];
        }

        if (!Block::whereKey($blockId)->exists()) {
            return ['error' => 'block_not_found', 'block_id' => $blockId];
        }

        $existing = BlockAction::where('block_id', $blockId)
            ->where('action_id', $actionId)
            ->first();

        $detach = ($args['action'] ?? 'attach') === 'detach';

        if ($detach) {
            $changed = $existing !== null;
            $existing?->delete();
        } else {
            $changed = $this->attach($existing, $blockId, $actionId, $args);
        }

        $result = [
            'status' => $detach ? 'detached' : 'attached',
            // Агент не знает исходного состояния: без флага он не отличит
            // «подключил» от «уже было подключено»
            'changed' => $changed,
            'block_id' => $blockId,
            'action' => ['id' => $action->id, 'slug' => $action->slug, 'name' => $action->name],
            'usage' => $detach ? null : "Данные доступны в шаблоне блока как \$actions['{$action->slug}'].",
            'warning' => !$detach && !$this->hasCode($action)
                ? 'У этого action ещё нет кода — блок получит пустой результат. Сохрани код через update_action_code.'
                : null,
        ];

        $result += $this->cacheAdvice($blockId, $detach);

        // Привязки в редакторе блока пишутся в базу мимо формы, поэтому запись
        // корректна и при открытом редакторе — но панель о ней не узнает
        if ($ctx && $ctx->isBlockEditorOpen($blockId)) {
            $result['editor_op'] = ['op' => 'refresh_actions', 'payload' => ['block_id' => $blockId]];
        }

        return array_filter($result, fn ($value) => $value !== null);
    }

    /**
     * @param  array<string,mixed>  $args
     * @return bool изменилось ли состояние привязки
     */
    private function attach(?BlockAction $existing, int $blockId, int $actionId, array $args): bool
    {
        $params = is_array($args['params'] ?? null) ? $args['params'] : null;
        $order = isset($args['order']) ? (int) $args['order'] : null;

        if (!$existing) {
            BlockAction::create([
                'block_id' => $blockId,
                'action_id' => $actionId,
                'params' => $params,
                'order' => $order ?? ((int) BlockAction::where('block_id', $blockId)->max('order') + 1),
            ]);

            return true;
        }

        $changed = false;

        if ($params !== null && $params !== ($existing->params ?? [])) {
            $existing->params = $params;
            $changed = true;
        }

        if ($order !== null && $order !== $existing->order) {
            $existing->order = $order;
            $changed = true;
        }

        if ($changed) {
            $existing->save();
        }

        return $changed;
    }

    /**
     * Что сделать с кэшем экземпляров блока. Кэш — флаг на каждом блоке
     * страницы, и движок его сам не трогает: закэшированный блок с action
     * покажет всем посетителям один и тот же результат.
     *
     * @return array<string,mixed>
     */
    private function cacheAdvice(int $blockId, bool $detach): array
    {
        if ($detach) {
            if (BlockAction::where('block_id', $blockId)->exists()) {
                return [];
            }

            $uncached = PageBlock::where('block_id', $blockId)->where('cache_enabled', false)->limit(50)->get(['id', 'page_id']);

            return $uncached->isEmpty() ? [] : [
                'uncached_page_blocks' => $uncached->map(fn (PageBlock $pb) => ['page_block_id' => $pb->id, 'page_id' => $pb->page_id])->all(),
                'cache_hint' => 'Actions у блока не осталось — кэш этих экземпляров можно снова включить через set_block_cache.',
            ];
        }

        $cached = PageBlock::where('block_id', $blockId)->where('cache_enabled', true)->limit(50)->get(['id', 'page_id']);

        return $cached->isEmpty() ? [] : [
            'cached_page_blocks' => $cached->map(fn (PageBlock $pb) => ['page_block_id' => $pb->id, 'page_id' => $pb->page_id])->all(),
            'cache_hint' => 'У этих экземпляров блока включён кэш — выключи его через set_block_cache, иначе результат action закэшируется и перестанет обновляться.',
        ];
    }

    /** Есть ли у action исполняемый код: без него привязка бессмысленна. */
    private function hasCode(Action $action): bool
    {
        if ($action->source !== 'storage') {
            return true;
        }

        return is_file(storage_path('cms/actions/' . basename($action->slug) . '.php'));
    }
}
