<?php

namespace Templite\Cms\Services\Ai\Tools;

use Templite\Cms\Services\Ai\EditorContext;

interface Tool
{
    public function name(): string;

    /** @return array<string,mixed> JSON-schema параметров (OpenAI function-calling `parameters`) */
    public function definition(): array;

    /**
     * @param  array<string,mixed>  $args
     * @return array<string,mixed> результат (сериализуется в tool-message)
     */
    public function execute(array $args, ?EditorContext $ctx): array;
}
