<?php

namespace Templite\Cms\Services\Ai\Runs;

use Illuminate\Support\Facades\DB;
use Templite\Cms\Models\AiChatMessage;
use Templite\Cms\Models\AiChatSession;
use Templite\Cms\Models\AiRun;
use Templite\Cms\Models\AiRunEvent;

class RunFinalizer
{
    /**
     * Закрыть ход: дописать error/done в журнал, собрать из журнала сообщение
     * ассистента и выставить статус. Ровно один раз — исполнитель, сторож и
     * «Стоп» могут прийти к одному ходу одновременно.
     *
     * $guard — условие закрытия, перепроверяемое под блокировкой строки: сторож
     * решил по устаревшей копии, а воркер мог только что взять ход и обновить
     * heartbeat — такой ход закрывать нельзя.
     *
     * @param  array{code:string,message:string}|null  $error
     * @param  (callable(AiRun):bool)|null  $guard
     * @return bool ход закрыт этим вызовом
     */
    public function finalize(AiRun $run, string $status, ?array $error = null, ?callable $guard = null): bool
    {
        // 3 попытки: параллельные закрытия одного хода могут поймать deadlock.
        return DB::transaction(function () use ($run, $status, $error, $guard) {
            $locked = AiRun::whereKey($run->id)->lockForUpdate()->first();

            if (! $locked || ! $locked->isActive()) {
                return false;
            }

            if ($guard && ! $guard($locked)) {
                return false;
            }

            $transcript = RunTranscript::fromRun($locked->id);
            $transcript->closeOpenStep();

            // Ответ из одних размышлений — тоже пустой: без текста и действий
            // он выпал бы из контекста, будто хода не было.
            if (! $error && $status === AiRun::COMPLETED && trim($transcript->answer) === '' && $transcript->toolLog === []) {
                $error = ['code' => 'empty_answer', 'message' => 'Модель вернула пустой ответ.'];
            }

            if ($error) {
                $this->event($locked->id, 'error', $error);
                $transcript->apply('error', $error);
            }

            // done — последнее событие хода: по нему подписка закрывается.
            $this->event($locked->id, 'done', ['reason' => $status]);

            if ($locked->assistant_message_id) {
                AiChatMessage::find($locked->assistant_message_id)?->update([
                    'content' => $transcript->answer,
                    'meta' => $transcript->meta(),
                ]);
            }

            $locked->update([
                'status' => $status,
                'finished_at' => now(),
                'error' => $error,
            ]);

            AiChatSession::whereKey($locked->session_id)->update(['updated_at' => now()]);

            return true;
        }, 3);
    }

    /** @param array<string,mixed> $payload */
    private function event(int $runId, string $type, array $payload): void
    {
        AiRunEvent::create(['run_id' => $runId, 'type' => $type, 'payload' => $payload, 'created_at' => now()]);
    }
}
