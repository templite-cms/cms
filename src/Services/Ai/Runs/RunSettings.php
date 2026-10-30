<?php

namespace Templite\Cms\Services\Ai\Runs;

/**
 * Настройки исполнения ходов агента. Дефолты продублированы здесь:
 * в опубликованном до этой правки config/cms.php секции ai может не быть.
 */
final class RunSettings
{
    /** Очередь есть и не выключена: иначе ход выполняется после ответа в том же процессе. */
    public static function useQueue(): bool
    {
        return (bool) config('cms.ai.queue', true) && config('queue.default') !== 'sync';
    }

    public static function queueName(): string
    {
        return (string) config('cms.ai.queue_name', 'default');
    }

    public static function maxTokens(): int
    {
        return (int) config('cms.ai.max_tokens', 8000);
    }

    public static function toolResultLimit(): int
    {
        return (int) config('cms.ai.tool_result_limit', 8000);
    }

    public static function compactThreshold(): float
    {
        return (float) config('cms.ai.compact_threshold', 0.7);
    }

    public static function defaultContextLength(): int
    {
        return (int) config('cms.ai.default_context_length', 32000);
    }

    public static function eventsTtlDays(): int
    {
        return (int) config('cms.ai.events_ttl_days', 7);
    }
}
