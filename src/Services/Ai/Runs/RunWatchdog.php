<?php

namespace Templite\Cms\Services\Ai\Runs;

use Templite\Cms\Models\AiRun;

/**
 * Сторож: закрывает ходы, исполнитель которых перестал подавать признаки
 * жизни (упал воркер, перезапустили контейнер). Без него диалог навсегда
 * остался бы «занят», а очередь сообщений — стоять.
 */
class RunWatchdog
{
    /** Исполнитель тикает раз в секунды; дольше всех молчит генерация картинки — до 180 с. */
    public const STALE_AFTER = 300;

    /** Ход без очереди, но так и не начался — процесс умер сразу после ответа. */
    public const NOT_STARTED_INLINE = 60;

    /**
     * Ход в очереди, но воркер его не взял. Воркер общий: ход может честно
     * ждать, пока он доделает другие ходы, поэтому порог большой.
     */
    public const NOT_STARTED_QUEUE = 900;

    public function __construct(private readonly RunFinalizer $finalizer)
    {
    }

    /** @return bool закрыт хотя бы один ход — пора запускать следующий из очереди */
    public function reapSession(int $sessionId): bool
    {
        $reaped = false;

        foreach (AiRun::where('session_id', $sessionId)->where('status', AiRun::RUNNING)->get() as $run) {
            if (! $this->isStale($run)) {
                continue;
            }

            $error = $run->started_at
                ? ['code' => 'interrupted', 'message' => 'Ход прерван: исполнитель перестал отвечать (перезапуск сервера или воркера).']
                : ['code' => 'not_started', 'message' => $this->isInline($run)
                    ? 'Ход не запустился. Попробуйте ещё раз.'
                    : 'Ход не запустился за 15 минут: обработчик очереди занят или не работает. Проверьте воркер очереди или выключите CMS_AI_QUEUE.'];

            // Под блокировкой ход перепроверяется: воркер мог взять его, пока мы решали.
            $reaped = $this->finalizer->finalize($run, AiRun::INTERRUPTED, $error, fn (AiRun $locked) => $this->isStale($locked)) || $reaped;
        }

        return $reaped;
    }

    public function isStale(AiRun $run): bool
    {
        $limit = match (true) {
            (bool) $run->started_at => self::STALE_AFTER,
            $this->isInline($run) => self::NOT_STARTED_INLINE,
            default => self::NOT_STARTED_QUEUE,
        };

        return ! $run->heartbeat_at || $run->heartbeat_at->lt(now()->subSeconds($limit));
    }

    /** Драйвер не записан — считаем очередью: большой порог не закроет живой ход. */
    private function isInline(AiRun $run): bool
    {
        return $run->driver === 'inline';
    }
}
