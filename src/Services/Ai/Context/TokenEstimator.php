<?php

namespace Templite\Cms\Services\Ai\Context;

use Templite\Cms\Models\AiChatSession;
use Templite\Cms\Services\Ai\Runs\RunSettings;

/**
 * Грубая оценка размера промпта. Точный токенизатор у каждой модели свой,
 * поэтому считаем символы и уточняем соотношение по реальному usage
 * прошлого запроса этого же диалога.
 */
class TokenEstimator
{
    private const DEFAULT_CHARS_PER_TOKEN = 3.5;

    public function charsPerToken(AiChatSession $session): float
    {
        $tokens = (int) $session->last_prompt_tokens;
        $chars = (int) $session->last_prompt_chars;

        if ($tokens > 0 && $chars > 0) {
            return max(1.5, min(6.0, $chars / $tokens));
        }

        return self::DEFAULT_CHARS_PER_TOKEN;
    }

    public static function chars(mixed $parts): int
    {
        return mb_strlen((string) json_encode($parts, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE));
    }

    /** Запрос из этих частей превышает порог сжатия от окна модели. */
    public function exceeds(AiChatSession $session, mixed $parts, int $window): bool
    {
        // Окно делится между промптом и ответом: под ответ резервируем max_tokens,
        // но не больше половины окна — иначе у маленьких моделей на промпт не останется ничего.
        $promptWindow = max($window - RunSettings::maxTokens(), (int) ($window * 0.5));
        $limit = $promptWindow * RunSettings::compactThreshold() * $this->charsPerToken($session);

        return self::chars($parts) > $limit;
    }

    /**
     * Запомнить реальный размер промпта.
     *
     * @param  array<string,mixed>  $payload
     * @param  array<string,mixed>|null  $usage
     */
    public function calibrate(AiChatSession $session, array $payload, ?array $usage): void
    {
        $tokens = (int) ($usage['prompt_tokens'] ?? 0);

        if ($tokens <= 0) {
            return;
        }

        $session->forceFill([
            'last_prompt_tokens' => $tokens,
            'last_prompt_chars' => self::chars([$payload['messages'] ?? [], $payload['tools'] ?? []]),
        ])->saveQuietly();
    }
}
