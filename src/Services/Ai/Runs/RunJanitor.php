<?php

namespace Templite\Cms\Services\Ai\Runs;

use Templite\Cms\Models\AiEditorOp;
use Templite\Cms\Models\AiRun;
use Templite\Cms\Models\AiRunEvent;

/**
 * Чистка: журнал закрытого хода нужен, только пока его может дочитать
 * браузер, — итог уже лежит в сообщении ассистента.
 */
class RunJanitor
{
    public function prune(): void
    {
        $border = now()->subDays(RunSettings::eventsTtlDays());

        AiRun::whereNotIn('status', AiRun::ACTIVE)
            ->where('finished_at', '<', $border)
            // Только ходы, у которых журнал ещё не вычищен, — иначе каждый
            // прогон заново перебирает всю историю.
            ->whereExists(fn ($q) => $q->selectRaw('1')->from('cms_ai_run_events')->whereColumn('cms_ai_run_events.run_id', 'cms_ai_runs.id'))
            ->select('id')
            ->chunkById(500, fn ($runs) => AiRunEvent::whereIn('run_id', $runs->pluck('id'))->delete());

        AiEditorOp::where('status', AiEditorOp::PENDING)
            ->where('created_at', '<', $border)
            ->update(['status' => AiEditorOp::DISCARDED]);
    }
}
