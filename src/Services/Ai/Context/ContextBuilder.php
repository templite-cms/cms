<?php

namespace Templite\Cms\Services\Ai\Context;

use Illuminate\Support\Collection;
use Templite\Cms\Models\AiChatMessage;
use Templite\Cms\Models\AiChatSession;
use Templite\Cms\Services\Ai\Runs\ToolLog;

/**
 * История диалога для модели. Сообщения после резюме плюс к ответам
 * ассистента — журнал его действий: иначе модель не помнит, что делала сама.
 */
class ContextBuilder
{
    /**
     * Сообщения после сжатой части до $uptoMessageId включительно. Более
     * поздние — это очередь: до них ход ещё не дошёл.
     *
     * @return Collection<int,AiChatMessage>
     */
    public function messagesSinceSummary(AiChatSession $session, int $uptoMessageId): Collection
    {
        return $session->messages()
            ->where('id', '<=', $uptoMessageId)
            ->when($session->summarized_until_message_id, fn ($q, $id) => $q->where('id', '>', $id))
            ->orderBy('id')
            ->get(['id', 'role', 'content', 'meta']);
    }

    /** @return array<int,array{role:string,content:string}> */
    public function history(AiChatSession $session, int $uptoMessageId): array
    {
        return $this->messagesSinceSummary($session, $uptoMessageId)
            ->map(fn (AiChatMessage $m) => ['role' => $m->role, 'content' => $this->render($m)])
            ->filter(fn (array $m) => $m['content'] !== '')
            ->values()
            ->all();
    }

    /** Текст сообщения для модели. Пустой — сообщение в контекст не идёт. */
    public function render(AiChatMessage $m): string
    {
        $content = (string) $m->content;

        if ($m->role !== 'assistant') {
            return $content;
        }

        $meta = $m->meta ?? [];
        $log = $meta['tool_log'] ?? [];
        $parts = [];

        if ($log) {
            $parts[] = '[Действия: '.ToolLog::render($log).']';
        }

        if ($content !== '') {
            $parts[] = $content;
        } elseif ($log || ! empty($meta['error'])) {
            // Ход без ответа раньше выпадал из контекста целиком — модель не знала,
            // что уже начала работу и на чём остановилась.
            // Сообщение ошибки обычно уже кончается точкой — без rtrim вышло бы «..».
            $reason = rtrim((string) ($meta['error']['message'] ?? ''), '. ');
            $parts[] = '[Ход прерван'.($reason !== '' ? ': '.$reason : '').'. Ответ пользователю не дан.]';
        }

        return implode("\n", $parts);
    }
}
