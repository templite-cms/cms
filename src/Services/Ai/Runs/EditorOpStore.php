<?php

namespace Templite\Cms\Services\Ai\Runs;

use Illuminate\Support\Collection;
use Templite\Cms\Models\AiEditorOp;
use Templite\Cms\Models\AiRun;
use Templite\Cms\Services\Ai\EditorContext;

/**
 * Правки, которые редактор применяет сам. Вкладку могли закрыть или
 * перезагрузить, пока агент работал, — правка дождётся следующего открытия.
 */
class EditorOpStore
{
    /** @return array{type:string,id:string}|null редактор, открытый при отправке сообщения */
    public static function targetOf(?EditorContext $ctx): ?array
    {
        return match (true) {
            $ctx === null => null,
            $ctx->isBlockEditorOpen() => ['type' => 'block', 'id' => (string) $ctx->blockId],
            $ctx->isActionEditorOpen() => ['type' => 'action', 'id' => (string) $ctx->actionId],
            $ctx->isTemplateEditorOpen() => ['type' => 'template', 'id' => (string) $ctx->templateId],
            $ctx->isSettingsEditorOpen() => ['type' => 'settings', 'id' => (string) $ctx->settingsScreen],
            $ctx->isOpen() => ['type' => 'page', 'id' => (string) $ctx->pageId],
            default => null,
        };
    }

    /** @param array{op:string,payload:array} $op */
    public function record(AiRun $run, ?EditorContext $ctx, array $op): ?AiEditorOp
    {
        $target = self::targetOf($ctx);

        if (! $target) {
            return null;
        }

        return AiEditorOp::create([
            'run_id' => $run->id,
            'manager_id' => $run->manager_id,
            'target_type' => $target['type'],
            'target_id' => $target['id'],
            'op' => $op,
            'status' => AiEditorOp::PENDING,
        ]);
    }

    /** @return Collection<int,AiEditorOp> */
    public function pendingFor(int $managerId, string $type, string $id): Collection
    {
        return AiEditorOp::where('manager_id', $managerId)
            ->where('target_type', $type)
            ->where('target_id', $id)
            ->where('status', AiEditorOp::PENDING)
            // Правку недельной давности нельзя молча накатывать поверх кода,
            // сохранённого с тех пор: она была предложена к другой версии.
            ->where('created_at', '>=', now()->subDay())
            ->orderBy('id')
            ->get(['id', 'op']);
    }

    /**
     * Забрать правку на применение. Атомарно: живое событие, загрузка при
     * открытии и соседняя вкладка не применят её дважды.
     */
    public function claim(int $opId, int $managerId): bool
    {
        return AiEditorOp::whereKey($opId)
            ->where('manager_id', $managerId)
            ->where('status', AiEditorOp::PENDING)
            ->update(['status' => AiEditorOp::APPLIED, 'applied_at' => now()]) === 1;
    }
}
