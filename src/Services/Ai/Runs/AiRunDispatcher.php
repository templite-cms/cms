<?php

namespace Templite\Cms\Services\Ai\Runs;

use Illuminate\Support\Facades\DB;
use Templite\Cms\Jobs\RunAgentJob;
use Templite\Cms\Models\AiChatSession;
use Templite\Cms\Models\AiRun;

/**
 * Запуск ходов. В диалоге одновременно идёт только один ход, остальные ждут
 * в порядке отправки. Способ запуска — очередь, если она есть, иначе тот же
 * PHP-процесс после отправки ответа браузеру.
 */
class AiRunDispatcher
{
    /** @var array<int,int> ходы, ждущие исполнения в этом процессе (драйвер inline) */
    private static array $inlineQueue = [];

    private static bool $draining = false;

    private static bool $scheduled = false;

    /** Запустить следующий ход диалога, если сейчас ни один не выполняется. */
    public function startNext(int $sessionId): void
    {
        $run = $this->claimNext($sessionId);

        if (! $run) {
            return;
        }

        if ($run->driver === 'queue') {
            // afterCommit: если startNext вызовут внутри внешней транзакции,
            // воркер не должен взять ход раньше, чем его статус станет виден.
            RunAgentJob::dispatch($run->id)->onQueue(RunSettings::queueName())->afterCommit();

            return;
        }

        self::$inlineQueue[] = $run->id;

        // Во время drainInline повторно не регистрируемся: Laravel 12 вызвал бы
        // и такой колбэк, но следующий ход и так подхватит тот же цикл drainInline.
        if (! self::$draining && ! self::$scheduled) {
            self::$scheduled = true;
            app()->terminating(fn () => $this->drainInline());
        }
    }

    private function claimNext(int $sessionId): ?AiRun
    {
        return DB::transaction(function () use ($sessionId) {
            // Блокировка строки диалога упорядочивает параллельные отправки:
            // без неё две вкладки запустили бы два хода одного диалога.
            AiChatSession::whereKey($sessionId)->lockForUpdate()->first(['id']);

            if (AiRun::where('session_id', $sessionId)->where('status', AiRun::RUNNING)->exists()) {
                return null;
            }

            $next = AiRun::where('session_id', $sessionId)
                ->where('status', AiRun::QUEUED)
                ->orderBy('id')
                ->first();

            if (! $next) {
                return null;
            }

            // heartbeat с момента запуска: если воркер ход так и не возьмёт,
            // сторож закроет его как not_started. Перевод условный: ход могли
            // закрыть «Стопом» в обход блокировки диалога — такой не оживляем.
            $claimed = AiRun::whereKey($next->id)->where('status', AiRun::QUEUED)->update([
                'status' => AiRun::RUNNING,
                'driver' => RunSettings::useQueue() ? 'queue' : 'inline',
                'heartbeat_at' => now(),
            ]);

            if ($claimed === 0) {
                return null;
            }

            return $next->refresh();
        }, 3); // «Стоп» queued-хода в RunFinalizer берёт те же строки — возможен deadlock.
    }

    private function drainInline(): void
    {
        // Ответ уже ушёл браузеру (Response::send зовёт fastcgi_finish_request):
        // дальше ходу не мешают ни обрыв соединения, ни max_execution_time.
        ignore_user_abort(true);
        set_time_limit(0);

        self::$draining = true;
        self::$scheduled = false;

        try {
            while (($id = array_shift(self::$inlineQueue)) !== null) {
                $run = AiRun::find($id);

                if ($run) {
                    app(AgentRunner::class)->run($run);
                }
            }
        } finally {
            self::$draining = false;
        }
    }
}
