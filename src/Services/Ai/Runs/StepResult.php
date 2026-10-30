<?php

namespace Templite\Cms\Services\Ai\Runs;

/** Итог одного шага модели. */
final class StepResult
{
    /**
     * @param  array<int,array<string,mixed>>  $toolCalls
     * @param  array<string,mixed>|null  $usage
     */
    public function __construct(
        public readonly string $text,
        public readonly array $toolCalls,
        public readonly ?string $finishReason,
        public readonly ?array $usage,
    ) {
    }
}
