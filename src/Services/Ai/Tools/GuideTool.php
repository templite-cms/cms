<?php

namespace Templite\Cms\Services\Ai\Tools;

use Templite\Cms\Services\Ai\EditorContext;

/**
 * Справка по разделу CMS: конвенции, ограничения валидатора, порядок работы.
 *
 * Справки вынесены из системного промпта в инструменты: конвенции всех
 * разделов вместе весили больше семи тысяч символов и уходили в каждый
 * запрос — даже когда речь шла не о вёрстке вовсе. Теперь агент берёт
 * нужный раздел по требованию, а в промпте остаётся указатель.
 */
abstract class GuideTool implements Tool
{
    public function definition(): array
    {
        return ['type' => 'object', 'properties' => new \stdClass()];
    }

    public function execute(array $args, ?EditorContext $ctx): array
    {
        return $this->guide();
    }

    /** @return array<string,mixed> содержание справки */
    abstract protected function guide(): array;
}
