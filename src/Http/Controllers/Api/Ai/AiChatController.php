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
use Templite\Cms\Services\Ai\Runs\RunJanitor;
use Templite\Cms\Services\Ai\Runs\RunWatchdog;

/**
 * Отправка сообщения ассистенту. Ответ не ждёт работы агента: создаёт ход
 * и сразу возвращает его id, события хода браузер читает из
 * GET /ai/runs/{id}/events.
 */
class AiChatController extends Controller
{
    public function __invoke(
        Request $request,
        AiKeyService $keys,
        ChatHistory $history,
        AiRunDispatcher $dispatcher,
        RunWatchdog $watchdog,
        RunJanitor $janitor,
    ): JsonResponse {
        $validated = $request->validate([
            'message' => ['required', 'string'],
            'session_id' => ['nullable', 'integer'],
            'context' => ['nullable', 'array'],
            'request_id' => ['nullable', 'string', 'max:64'],
            'model' => ['nullable', 'string', 'max:120'],
            'image_model' => ['nullable', 'string', 'max:120'],
        ]);

        $manager = auth()->user() ?? auth('manager')->user();
        $key = $keys->activeFor($manager);

        // Без ключа разговора не выйдет — отказываем сразу, не заводя диалог:
        // иначе каждое сообщение плодило бы пустую сессию в истории.
        if (! $keys->canUse($manager) || ! $key) {
            return $this->error('Ключ AI не выбран — выберите рабочий ключ в настройках ассистента.', 422, ['code' => 'no_key']);
        }

        // Повтор уже принятого сообщения — отдаём тот же ход, ничего не создавая
        // и не трогая модели диалога. Ищем до выбора диалога: у первого
        // сообщения нового диалога session_id нет, и sessionFor завёл бы второй.
        if (! empty($validated['request_id'])) {
            $existing = $history->findTurnByRequest((int) $manager->id, $validated['request_id']);

            if ($existing) {
                return $this->turnResponse($existing->session, $existing);
            }
        }

        $resolved = $history->sessionFor($validated['session_id'] ?? null, (int) $manager->id, $key);
        $session = $resolved->session;

        // Модели могли сменить прямо в панели — запоминаем на диалоге. Но если
        // диалог только что переехал на другой ключ, присланные модели относятся
        // к прежнему провайдеру: панель получит новые значения в ответе.
        $models = $resolved->keyChanged ? [] : array_filter([
            'model' => $validated['model'] ?? null,
            'image_model' => $validated['image_model'] ?? null,
        ]);

        if ($models && array_diff_assoc($models, $session->only(['model', 'image_model']))) {
            $session->update($models);
        }

        $run = $history->startTurn($session, $validated['message'], $validated['request_id'] ?? null, $validated['context'] ?? null);

        // Прежний ход мог умереть вместе с воркером — иначе новое сообщение
        // навсегда встало бы в очередь за ним.
        $watchdog->reapSession($session->id);
        $dispatcher->startNext($session->id);

        if (random_int(1, 50) === 1) {
            $janitor->prune();
        }

        $run->refresh();

        return $this->turnResponse($session, $run);
    }

    private function turnResponse(AiChatSession $session, AiRun $run): JsonResponse
    {
        return $this->success([
            'session' => [
                'id' => $session->id,
                'title' => $session->title,
                'model' => $session->model,
                'image_model' => $session->image_model,
                'key_id' => $session->key_id,
            ],
            'run' => $run->brief(),
            'user_message_id' => $run->message_id,
            'assistant_message_id' => $run->assistant_message_id,
        ]);
    }
}
