<?php

namespace Templite\Cms\Services\Ai\Context;

use Illuminate\Support\Collection;
use Templite\Cms\Models\AiChatMessage;
use Templite\Cms\Models\AiChatSession;
use Templite\Cms\Services\Ai\OpenRouterClient;

/**
 * Сжатие истории: старая часть диалога сворачивается в резюме, которое идёт
 * в system prompt вместо самих сообщений. Переписка в интерфейсе не меняется.
 */
class ContextCompactor
{
    /** Сколько последних сообщений остаётся дословно. */
    public const KEEP_RECENT = 6;

    /** Потолок пересказываемого текста: сам запрос на сжатие тоже должен влезть в окно. */
    private const MAX_TRANSCRIPT = 60000;

    private const MAX_MESSAGE = 4000;

    /** Сколько порций пересказывается за один вызов: длинный хвост доберёт следующий ход. */
    private const MAX_ITERATIONS = 5;

    private const INSTRUCTIONS = <<<'TXT'
Ты сжимаешь историю диалога пользователя с AI-ассистентом CMS Templite, чтобы ассистент мог продолжить работу без полной переписки.
Напиши краткое содержание на русском, до 2000 символов, по пунктам:
- цель и задачи пользователя;
- что ассистент уже сделал: какие сущности создал или изменил (тип, id, slug, название);
- договорённости, предпочтения и ограничения пользователя;
- что осталось сделать и открытые вопросы.
Не выдумывай. Без вступлений — только содержание.
TXT;

    public function __construct(private readonly ContextBuilder $context)
    {
    }

    /**
     * Пересказ идёт порциями с начала несжатой части: каждая порция
     * дописывается к прежнему резюме, поэтому начало диалога не теряется,
     * даже если старых сообщений больше, чем влезает в один запрос.
     *
     * @return bool сжатие случилось
     */
    public function compact(AiChatSession $session, OpenRouterClient $client, string $model, int $uptoMessageId, int $keepRecent = self::KEEP_RECENT): bool
    {
        $compacted = false;

        for ($i = 0; $i < self::MAX_ITERATIONS; $i++) {
            $rows = $this->context->messagesSinceSummary($session, $uptoMessageId);

            if ($rows->count() <= $keepRecent) {
                break;
            }

            $candidates = $rows->slice(0, $rows->count() - $keepRecent)->values();
            [$transcript, $last] = $this->portion($candidates);

            $input = ($session->summary ? "Прежнее краткое содержание:\n{$session->summary}\n\n" : '')
                ."Новая часть диалога:\n".$transcript;

            $summary = trim($client->complete([
                'model' => $model,
                'messages' => [
                    ['role' => 'system', 'content' => self::INSTRUCTIONS],
                    ['role' => 'user', 'content' => $input],
                ],
                'max_tokens' => 1500,
            ]));

            if ($summary === '') {
                break;
            }

            $session->forceFill([
                'summary' => $summary,
                'summarized_until_message_id' => $last->id,
            ])->save();

            $compacted = true;
        }

        return $compacted;
    }

    /**
     * Порция с начала кандидатов, пока транскрипт влезает в MAX_TRANSCRIPT.
     * Хотя бы одно сообщение берётся всегда — иначе сжатие встанет на месте.
     *
     * @param  Collection<int,AiChatMessage>  $candidates
     * @return array{0:string,1:AiChatMessage} транскрипт и последнее сообщение порции
     */
    private function portion(Collection $candidates): array
    {
        $parts = [];
        $length = 0;
        $last = null;

        foreach ($candidates as $m) {
            $text = mb_substr($this->context->render($m), 0, self::MAX_MESSAGE);
            $line = $text === '' ? '' : ($m->role === 'user' ? 'Пользователь' : 'Ассистент').': '.$text;
            $added = $line === '' ? 0 : mb_strlen($line) + ($parts === [] ? 0 : 2);

            if ($last !== null && $length + $added > self::MAX_TRANSCRIPT) {
                break;
            }

            if ($line !== '') {
                $parts[] = $line;
                $length += $added;
            }

            $last = $m;
        }

        return [implode("\n\n", $parts), $last];
    }
}
