<?php

namespace Templite\Cms\Services\Mcp;

use Templite\Cms\Services\Ai\AiTextRepository;

/** Сценарии (MCP prompts): список из prompts.php, тексты в три слоя. */
class McpPrompts
{
    /** Сценарии без явного profiles — про сборку сайта: в content их инструментов нет. */
    private const DEFAULT_PROFILES = ['builder', 'full'];

    public function __construct(private readonly AiTextRepository $texts)
    {
    }

    /**
     * @param  string|null  $profile  профиль токена; null — все сценарии
     * @return array<int,array{name:string,description:string,arguments:array<int,array<string,mixed>>}>
     */
    public function list(?string $profile = null): array
    {
        $result = [];

        foreach ($this->meta() as $name => $meta) {
            if ($this->allowed($meta, $profile)) {
                $result[] = ['name' => $name, 'description' => $meta['description'], 'arguments' => $meta['arguments']];
            }
        }

        return $result;
    }

    /**
     * @param  array<string,string|null>  $args
     * @return string|null null — нет такого сценария или он вне профиля
     */
    public function render(string $name, array $args, ?string $profile = null): ?string
    {
        $meta = $this->meta()[$name] ?? null;

        if ($meta === null || ! $this->allowed($meta, $profile)) {
            return null;
        }

        $text = $this->texts->get("prompts/{$name}.md");

        // Подставляются только объявленные аргументы: в текстах есть Blade-примеры {{ $fields[...] }}.
        foreach ($meta['arguments'] as $argument) {
            $text = str_replace('{{'.$argument['name'].'}}', (string) ($args[$argument['name']] ?? ''), $text);
        }

        return $text;
    }

    /** @param  array<string,mixed>  $meta */
    private function allowed(array $meta, ?string $profile): bool
    {
        return $profile === null || in_array($profile, $meta['profiles'] ?? self::DEFAULT_PROFILES, true);
    }

    /** @return array<string,array{description:string,arguments:array<int,array<string,mixed>>,profiles?:array<int,string>}> */
    private function meta(): array
    {
        return require __DIR__.'/../../../resources/ai/prompts/prompts.php';
    }
}
