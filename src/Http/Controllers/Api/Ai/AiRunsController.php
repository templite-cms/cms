<?php

namespace Templite\Cms\Http\Controllers\Api\Ai;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Templite\Cms\Http\Controllers\Api\Controller;
use Templite\Cms\Models\AiChatMessage;
use Templite\Cms\Models\AiChatSession;
use Templite\Cms\Models\AiRun;
use Templite\Cms\Models\AiRunEvent;
use Templite\Cms\Services\Ai\Runs\AiRunDispatcher;
use Templite\Cms\Services\Ai\Runs\RunFinalizer;
use Templite\Cms\Services\Ai\Runs\RunWatchdog;

/** Ход агента: чтение журнала, остановка, удаление из очереди. */
class AiRunsController extends Controller
{
    /** Поток живёт недолго: так он не упирается в таймауты nginx и не держит PHP-процесс. */
    private const STREAM_SECONDS = 25;

    private const POLL_INTERVAL_US = 300_000;

    private const PING_SECONDS = 15;

    private const REAP_SECONDS = 5;

    private const BATCH = 500;

    /**
     * События хода после курсора after. Браузер переподключается сам —
     * по закрытию потока или после обрыва, с последнего полученного id.
     */
    public function events(Request $request, int $id, RunWatchdog $watchdog, AiRunDispatcher $dispatcher): StreamedResponse|JsonResponse
    {
        $run = $this->findOwn($id);

        if (! $run) {
            return $this->error('Ход не найден.', 404);
        }

        $after = max(0, (int) $request->query('after', 0));

        return new StreamedResponse(function () use ($run, $after, $watchdog, $dispatcher) {
            $cursor = $after;
            $startedAt = microtime(true);
            $lastPing = $startedAt;
            $lastReap = 0.0;

            while (true) {
                $events = AiRunEvent::where('run_id', $run->id)
                    ->where('id', '>', $cursor)
                    ->orderBy('id')
                    ->limit(self::BATCH)
                    ->get();

                foreach ($events as $event) {
                    $cursor = $event->id;
                    $this->send($event);

                    if ($event->type === 'done') {
                        return;
                    }
                }

                if ($events->count() === self::BATCH) {
                    continue;
                }

                $now = microtime(true);

                if ($now - $startedAt >= self::STREAM_SECONDS || connection_aborted()) {
                    return;
                }

                if ($now - $lastPing >= self::PING_SECONDS) {
                    echo ": ping\n\n";
                    $this->flushOutput();
                    $lastPing = $now;
                }

                // Исполнитель мог умереть — закрываем ход, чтобы браузер не ждал вечно.
                if ($now - $lastReap >= self::REAP_SECONDS) {
                    $lastReap = $now;

                    if ($watchdog->reapSession($run->session_id)) {
                        $dispatcher->startNext($run->session_id);
                    }

                    // Ход закрыт, а done мы не видели. Сначала — всё, что успело
                    // появиться после курсора: ход мог закрыться между выборкой
                    // и этой проверкой. Если done среди них нет — при двух
                    // писателях (зависший исполнитель и сторож) меньший id мог
                    // закоммититься позже большего, и курсор через него
                    // перешагнул: досылаем итог (error/done) из-за курсора.
                    if (! $run->fresh()?->isActive()) {
                        $tail = AiRunEvent::where('run_id', $run->id)
                            ->where('id', '>', $cursor)
                            ->orderBy('id')
                            ->get();

                        $tail->each(fn (AiRunEvent $event) => $this->send($event));

                        if (! $tail->contains('type', 'done')) {
                            AiRunEvent::where('run_id', $run->id)
                                ->where('id', '<=', $cursor)
                                ->whereIn('type', ['error', 'done'])
                                ->orderBy('id')
                                ->get()
                                ->each(fn (AiRunEvent $event) => $this->send($event));
                        }

                        return;
                    }
                }

                usleep(self::POLL_INTERVAL_US);
            }
        }, 200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache',
            'X-Accel-Buffering' => 'no',
        ]);
    }

    /** «Стоп»: ход остановится на ближайшей проверке, сделанное сохранится. */
    public function cancel(int $id, RunFinalizer $finalizer, AiRunDispatcher $dispatcher): JsonResponse
    {
        $run = $this->findOwn($id);

        if (! $run) {
            return $this->error('Ход не найден.', 404);
        }

        // Исполнитель за ход ещё не взялся — закрывать некому, закрываем сами.
        // Проверка повторяется под блокировкой: исполнитель мог взять ход
        // между чтением и закрытием — тогда просим его остановиться.
        $notStarted = fn (AiRun $r) => $r->status === AiRun::QUEUED || ! $r->started_at;

        if ($run->isActive() && $notStarted($run)
            && $finalizer->finalize($run, AiRun::CANCELLED, ['code' => 'cancelled', 'message' => 'Остановлено.'], $notStarted)) {
            $dispatcher->startNext($run->session_id);
        } else {
            AiRun::whereKey($run->id)->where('status', AiRun::RUNNING)->update(['cancel_requested_at' => now()]);
        }

        return $this->success($run->fresh()->brief());
    }

    /** Убрать из очереди сообщение, до которого ход ещё не дошёл. */
    public function destroy(int $id): JsonResponse
    {
        $run = $this->findOwn($id);

        if (! $run) {
            return $this->error('Ход не найден.', 404);
        }

        $status = DB::transaction(function () use ($run) {
            // Порядок блокировок как в AiRunDispatcher::claimNext: сначала
            // диалог, потом ход — иначе встречные транзакции ждут друг друга.
            AiChatSession::whereKey($run->session_id)->lockForUpdate()->first(['id']);

            $locked = AiRun::whereKey($run->id)->lockForUpdate()->first();

            if (! $locked || $locked->status !== AiRun::QUEUED) {
                return $locked?->status;
            }

            // Ход удалится каскадом вместе с сообщением пользователя.
            AiChatMessage::whereKey(array_filter([$locked->message_id, $locked->assistant_message_id]))->delete();

            return true;
        }, 3);

        if ($status === AiRun::RUNNING) {
            return $this->error('Ход уже начался — убрать его из очереди нельзя.', 409);
        }

        if ($status !== true) {
            return $this->error('Ход уже завершён.', 409);
        }

        return $this->success(null, 'Сообщение убрано из очереди.');
    }

    private function send(AiRunEvent $event): void
    {
        echo 'id: '.$event->id."\n";
        echo 'event: '.$event->type."\n";
        echo 'data: '.json_encode($event->payload ?: new \stdClass(), JSON_UNESCAPED_UNICODE)."\n\n";
        $this->flushOutput();
    }

    private function flushOutput(): void
    {
        if (ob_get_level() > 0) {
            @ob_flush();
        }

        flush();
    }

    /** Чужие ходы не отдаём: иначе переписку можно вытащить, подставив id. */
    private function findOwn(int $id): ?AiRun
    {
        return AiRun::where('manager_id', $this->managerId())->find($id);
    }

    private function managerId(): int
    {
        $user = auth()->user() ?? auth('manager')->user();

        return (int) ($user?->id ?? 0);
    }
}
