<?php

namespace Templite\Cms\Http\Controllers\Api\Ai;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Templite\Cms\Http\Controllers\Api\Controller;
use Templite\Cms\Models\AiChatSession;
use Templite\Cms\Models\AiRun;
use Templite\Cms\Services\Ai\AiKeyService;
use Templite\Cms\Services\Ai\ChatHistory;
use Templite\Cms\Services\Ai\Runs\AiRunDispatcher;
use Templite\Cms\Services\Ai\Runs\RunWatchdog;

/**
 * Диалоги с AI-ассистентом: список, чтение, создание, удаление.
 */
class AiSessionsController extends Controller
{
    public function __construct(private readonly ChatHistory $history)
    {
    }

    public function index(): JsonResponse
    {
        $sessions = AiChatSession::where('manager_id', $this->managerId())
            ->withCount('messages')
            ->orderByDesc('updated_at')
            ->get(['id', 'title', 'updated_at'])
            ->map(fn (AiChatSession $s) => [
                'id' => $s->id,
                'title' => $s->title ?: 'Новый диалог',
                'updated_at' => $s->updated_at?->toIso8601String(),
                'messages_count' => $s->messages_count,
            ]);

        return $this->success($sessions);
    }

    /**
     * Сообщения отдаются с конца порциями: длинная переписка иначе приезжала бы
     * целиком, а нужен последний экран. Прокрутка вверх запрашивает предыдущие
     * через before_id. Вместе с сообщениями — статусы их ходов и идущий ход:
     * по нему панель подключается к журналу после перезагрузки.
     */
    public function show(Request $request, int $id, RunWatchdog $watchdog, AiRunDispatcher $dispatcher): JsonResponse
    {
        $session = $this->findOwn($id);

        if (! $session) {
            return $this->error('Диалог не найден.', 404);
        }

        // startNext дешёвый и идемпотентный: заодно поднимает очередь, если
        // процесс упал между созданием хода и его запуском.
        $watchdog->reapSession($session->id);
        $dispatcher->startNext($session->id);

        $limit = min(max((int) $request->input('limit', 30), 1), 100);
        $beforeId = (int) $request->input('before_id');

        $query = $session->messages()->orderByDesc('id')->limit($limit + 1);

        if ($beforeId > 0) {
            $query->where('id', '<', $beforeId);
        }

        // Лишняя запись сверх лимита говорит, что выше есть ещё.
        $rows = $query->get();
        $hasMore = $rows->count() > $limit;
        $messages = $rows->take($limit)->reverse()->values();
        $ids = $messages->pluck('id');

        $runs = AiRun::where('session_id', $session->id)
            ->where(fn ($q) => $q->whereIn('message_id', $ids)->orWhereIn('assistant_message_id', $ids))
            ->get(['id', 'status', 'message_id', 'assistant_message_id']);

        $runOf = function ($m) use ($runs) {
            $run = $runs->first(fn (AiRun $r) => $m->role === 'user'
                ? $r->message_id === $m->id
                : $r->assistant_message_id === $m->id);

            return $run ? ['id' => $run->id, 'status' => $run->status] : null;
        };

        $active = AiRun::where('session_id', $session->id)->where('status', AiRun::RUNNING)->first();

        // Картинка ждёт решения, пока файл в карантине: кнопки «Принять/Отклонить»
        // должны пережить и окончание хода, и перезагрузку.
        $imageIds = $messages->flatMap(fn ($m) => collect($m->meta['images'] ?? [])->pluck('file_id'))->filter()->unique();
        $pendingImages = $imageIds->isEmpty()
            ? collect()
            : \Templite\Cms\Models\File::whereIn('id', $imageIds)->where('ai_pending', true)->pluck('id');

        return $this->success([
            'id' => $session->id,
            'title' => $session->title,
            'model' => $session->model,
            'image_model' => $session->image_model,
            'has_more' => $hasMore,
            'active_run' => $active?->brief(),
            'messages' => $messages->map(fn ($m) => [
                'id' => $m->id,
                'role' => $m->role,
                'content' => (string) $m->content,
                'tools' => $m->meta['tools'] ?? [],
                'links' => $m->meta['links'] ?? [],
                'images' => collect($m->meta['images'] ?? [])
                    ->map(fn ($img) => $img + ['pending' => $pendingImages->contains($img['file_id'] ?? null)])
                    ->all(),
                // Старые сообщения шагов не имеют — панель покажет их как раньше.
                'steps' => $m->meta['steps'] ?? [],
                'error' => $m->meta['error'] ?? null,
                'truncated' => (bool) ($m->meta['truncated'] ?? false),
                'compacted' => (bool) ($m->meta['compacted'] ?? false),
                'run' => $runOf($m),
            ])->all(),
        ]);
    }

    /** Смена моделей диалога — выбор в панели сохраняется сразу, без отправки сообщения. */
    public function update(Request $request, int $id): JsonResponse
    {
        $session = $this->findOwn($id);

        if (! $session) {
            return $this->error('Диалог не найден.', 404);
        }

        $data = $request->validate([
            'model' => ['nullable', 'string', 'max:120'],
            'image_model' => ['nullable', 'string', 'max:120'],
        ]);

        $session->update(array_intersect_key($data, array_flip(['model', 'image_model'])));

        return $this->success([
            'id' => $session->id,
            'model' => $session->model,
            'image_model' => $session->image_model,
        ]);
    }

    public function store(AiKeyService $keys): JsonResponse
    {
        $manager = auth()->user() ?? auth('manager')->user();
        $session = $this->history->createSession($manager->id, $keys->activeFor($manager));

        return $this->success(['id' => $session->id, 'title' => null], 'Диалог создан.', 201);
    }

    public function destroy(int $id): JsonResponse
    {
        $session = $this->findOwn($id);

        if (! $session) {
            return $this->error('Диалог не найден.', 404);
        }

        $session->delete();

        return $this->success(null, 'Диалог удалён.');
    }

    /** Чужие сессии не отдаём: иначе переписку можно вытащить, подставив id. */
    private function findOwn(int $id): ?AiChatSession
    {
        return AiChatSession::where('manager_id', $this->managerId())->find($id);
    }

    private function managerId(): int
    {
        $user = auth()->user() ?? auth('manager')->user();

        return (int) ($user?->id ?? 0);
    }
}
