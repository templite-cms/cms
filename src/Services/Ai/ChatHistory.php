<?php

namespace Templite\Cms\Services\Ai;

use Illuminate\Support\Facades\DB;
use Templite\Cms\Models\AiChatMessage;
use Templite\Cms\Models\AiChatSession;
use Templite\Cms\Models\AiKey;
use Templite\Cms\Models\AiRun;

/**
 * История диалогов с ассистентом: сессии, начало хода и ретеншн.
 */
class ChatHistory
{
    /** Сколько сессий держим на менеджера. */
    public const KEEP_SESSIONS = 50;

    public function sessionFor(?int $sessionId, int $managerId, ?AiKey $key): ResolvedSession
    {
        if ($sessionId) {
            $session = AiChatSession::where('manager_id', $managerId)->find($sessionId);

            if ($session) {
                return $this->syncKey($session, $key);
            }
        }

        return new ResolvedSession($this->createSession($managerId, $key), false);
    }

    public function createSession(int $managerId, ?AiKey $key = null, ?string $model = null): AiChatSession
    {
        // Новый диалог продолжает на тех же моделях, что и прошлый: выбирать
        // их каждый раз заново — лишний шаг.
        $last = AiChatSession::where('manager_id', $managerId)
            ->where('key_id', $key?->id)
            ->latest('updated_at')
            ->first(['model', 'image_model']);

        $session = AiChatSession::create([
            'manager_id' => $managerId,
            'key_id' => $key?->id,
            'model' => $model ?? $last?->model,
            'image_model' => $last?->image_model,
        ]);

        $this->prune($managerId);

        return $session;
    }

    /**
     * Рабочий ключ сменился — модели прежнего провайдера на этом диалоге
     * больше не годятся, сбрасываем их на дефолты нового ключа.
     */
    private function syncKey(AiChatSession $session, ?AiKey $key): ResolvedSession
    {
        if (! $key || $session->key_id === $key->id) {
            return new ResolvedSession($session, false);
        }

        $session->update([
            'key_id' => $key->id,
            'model' => null,
            'image_model' => null,
        ]);

        return new ResolvedSession($session, true);
    }

    /**
     * Начать ход: сообщение пользователя, пустое сообщение ассистента и сам ход.
     *
     * Сообщение ассистента заводится сразу: пока идёт первый ход, пользователь
     * может написать второе, и ответ на первое должен остаться выше него.
     * Повторная отправка с тем же request_id возвращает уже созданный ход:
     * иначе сетевой ретрай раздваивал бы диалог.
     *
     * @param  array<string,mixed>|null  $context  снапшот открытого редактора
     */
    public function startTurn(AiChatSession $session, string $content, ?string $requestId, ?array $context): AiRun
    {
        return DB::transaction(function () use ($session, $content, $requestId, $context) {
            // Две вкладки или двойной клик шлют ходы одного диалога одновременно.
            // Без блокировки родителя на MySQL вставки сообщений берут S-блокировку
            // диалога от FK, а touch() повышает её до X — взаимоблокировка; на PG
            // оба запроса проходят проверку request_id и раздваивают ход. Заодно
            // сохраняется порядок id «вопрос → ответ». Порядок блокировок тот же,
            // что в AiRunDispatcher::claimNext: сначала диалог, потом его строки.
            AiChatSession::whereKey($session->id)->lockForUpdate()->first(['id']);

            if ($requestId) {
                $existing = $session->messages()
                    ->where('role', 'user')
                    ->where('meta->request_id', $requestId)
                    ->first(['id']);

                $run = $existing ? AiRun::where('message_id', $existing->id)->first() : null;

                if ($run) {
                    return $run;
                }
            }

            $user = $session->messages()->create([
                'role' => 'user',
                'content' => $content,
                'meta' => ['request_id' => $requestId],
                'created_at' => now(),
            ]);

            $assistant = $session->messages()->create([
                'role' => 'assistant',
                'content' => '',
                'meta' => [],
                'created_at' => now(),
            ]);

            if (! $session->title) {
                $session->title = mb_substr(trim($content), 0, 60);
            }

            // touch() сохраняет и грязные атрибуты — title уйдёт тем же запросом.
            $session->touch();

            return AiRun::create([
                'session_id' => $session->id,
                'manager_id' => $session->manager_id,
                'message_id' => $user->id,
                'assistant_message_id' => $assistant->id,
                'status' => AiRun::QUEUED,
                'context' => $context,
            ]);
        }, 3);
    }

    /**
     * Ход, уже начатый сообщением с этим request_id, — в любом диалоге менеджера.
     *
     * Нужен до выбора диалога: первое сообщение нового диалога приходит без
     * session_id, и его сетевой повтор иначе завёл бы второй диалог — поиск
     * в startTurn идёт только внутри сессии. Окно в 10 минут: ретраи приходят
     * сразу, а полный перебор истории на каждое сообщение не нужен.
     */
    public function findTurnByRequest(int $managerId, string $requestId): ?AiRun
    {
        $message = AiChatMessage::query()
            ->where('role', 'user')
            ->where('meta->request_id', $requestId)
            ->where('created_at', '>=', now()->subMinutes(10))
            ->whereHas('session', fn ($q) => $q->where('manager_id', $managerId))
            ->orderByDesc('id')
            ->first(['id']);

        return $message ? AiRun::where('message_id', $message->id)->first() : null;
    }

    private function prune(int $managerId): void
    {
        $keepIds = AiChatSession::where('manager_id', $managerId)
            ->orderByDesc('updated_at')
            ->limit(self::KEEP_SESSIONS)
            ->pluck('id');

        AiChatSession::where('manager_id', $managerId)
            ->whereNotIn('id', $keepIds)
            ->delete();
    }
}
