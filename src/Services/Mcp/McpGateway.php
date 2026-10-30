<?php

namespace Templite\Cms\Services\Mcp;

use Templite\Cms\Services\Ai\AiTextRepository;
use Templite\Cms\Services\Ai\Tools\ToolCatalog;
use Templite\Cms\Services\Ai\Tools\ToolRegistry;

/**
 * MCP поверх реестра инструментов ассистента: манифест для прокси и вызов
 * инструмента от имени владельца токена, без контекста редактора.
 */
class McpGateway
{
    /** Версия контракта шлюза ↔ прокси. Меняется только при несовместимых правках формата. */
    public const PROTOCOL = 1;

    /** Таймаут прокси (с) для долгих инструментов; остальные — по умолчанию прокси. */
    private const TIMEOUTS = [
        'test_action' => 120,
        'upload_media_from_url' => 120,
        'add_cdn_library' => 120,
    ];

    public function __construct(
        private readonly ToolRegistry $tools,
        private readonly AiTextRepository $texts,
        private readonly McpPrompts $prompts,
    ) {
    }

    /** @return array<string,mixed> */
    public function manifest(McpScope $scope): array
    {
        $body = [
            'profile' => $scope->profile,
            'mode' => $scope->mode,
            'abilities' => $scope->abilities,
            'instructions' => trim($this->texts->get('mcp.md'))."\n\n".trim($this->texts->get('instructions.md')),
            'tools' => $scope->mode === McpScope::MODE_DISCOVERY ? $this->metaTools() : $this->toolList($scope),
            'prompts' => $this->prompts->list($scope->profile),
        ];

        return [
            'protocol' => self::PROTOCOL,
            'hash' => sha1((string) json_encode($body, JSON_UNESCAPED_UNICODE)),
            'server' => ['name' => 'templite', 'cms_version' => $this->cmsVersion()],
        ] + $body;
    }

    private function cmsVersion(): ?string
    {
        try {
            return \Composer\InstalledVersions::getPrettyVersion('templite/cms');
        } catch (\OutOfBoundsException) {
            return null;
        }
    }

    /**
     * @param  array<string,mixed>  $args
     * @return array{ok:bool,text:string}
     */
    public function call(McpScope $scope, string $name, array $args): array
    {
        if ($scope->mode === McpScope::MODE_DISCOVERY) {
            return match ($name) {
                'search_tools' => $this->ok(['tools' => $this->search($scope, (string) ($args['query'] ?? ''))]),
                'describe_tool' => $this->describe($scope, (string) ($args['name'] ?? '')),
                'call_tool' => is_array($args['arguments'] ?? [])
                    ? $this->callTool($scope, (string) ($args['name'] ?? ''), $args['arguments'] ?? [])
                    : $this->fail('validation_failed', 'arguments должен быть объектом по схеме инструмента из describe_tool.'),
                default => $this->fail('unknown_tool', 'В режиме discovery доступны только search_tools, describe_tool и call_tool.'),
            };
        }

        return $this->callTool($scope, $name, $args);
    }

    /**
     * @param  array<string,mixed>  $args
     * @return array{ok:bool,text:string}
     */
    private function callTool(McpScope $scope, string $name, array $args): array
    {
        if (! $this->tools->allows($name, ToolCatalog::CHANNEL_MCP, $scope)) {
            return $this->tools->has($name)
                ? $this->fail('forbidden', "Инструмент {$name} недоступен этому токену: не хватает ability или он вне профиля {$scope->profile}.")
                : $this->fail('unknown_tool', "Инструмента {$name} нет.");
        }

        $result = $this->tools->execute($name, $args, null, ToolCatalog::CHANNEL_MCP);

        return ['ok' => ! isset($result['error']), 'text' => $this->tools->encodeResult($name, $result)];
    }

    /** @return array<int,array<string,mixed>> */
    private function toolList(McpScope $scope): array
    {
        return array_map(fn (array $d) => array_filter([
            'name' => $d['name'],
            'description' => $d['description'],
            'inputSchema' => $d['parameters'],
            'timeout' => self::TIMEOUTS[$d['name']] ?? null,
        ], fn ($v) => $v !== null), $this->tools->definitions(ToolCatalog::CHANNEL_MCP, $scope));
    }

    /** @return array<int,array{name:string,summary:string}> */
    private function search(McpScope $scope, string $query): array
    {
        $query = trim($query);
        $found = [];

        foreach ($this->tools->definitions(ToolCatalog::CHANNEL_MCP, $scope) as $d) {
            if ($query === '' || mb_stripos($d['name'].' '.$d['description'], $query) !== false) {
                $found[] = ['name' => $d['name'], 'summary' => (string) strtok($d['description'], '.').'.'];
            }
        }

        return $found;
    }

    /** @return array{ok:bool,text:string} */
    private function describe(McpScope $scope, string $name): array
    {
        foreach ($this->tools->definitions(ToolCatalog::CHANNEL_MCP, $scope) as $d) {
            if ($d['name'] === $name) {
                return $this->ok($d);
            }
        }

        return $this->fail('unknown_tool', "Инструмента {$name} нет или он недоступен этому токену. Найди нужный через search_tools.");
    }

    /** @return array<int,array<string,mixed>> */
    private function metaTools(): array
    {
        return [
            [
                'name' => 'search_tools',
                'description' => 'Найти инструменты Templite CMS по слову из названия или описания (пустой query — все). Возвращает имена и краткие описания.',
                'inputSchema' => ['type' => 'object', 'properties' => ['query' => ['type' => 'string', 'description' => 'Например: page, блок, preset, media']]],
            ],
            [
                'name' => 'describe_tool',
                'description' => 'Полное описание и JSON-схема параметров инструмента. Вызывай перед первым call_tool этого инструмента.',
                'inputSchema' => ['type' => 'object', 'properties' => ['name' => ['type' => 'string']], 'required' => ['name']],
            ],
            [
                'name' => 'call_tool',
                'description' => 'Вызвать инструмент Templite CMS по имени с аргументами по его схеме из describe_tool.',
                'inputSchema' => ['type' => 'object', 'properties' => [
                    'name' => ['type' => 'string'],
                    'arguments' => ['type' => 'object', 'description' => 'Аргументы по схеме инструмента'],
                ], 'required' => ['name']],
                'timeout' => 120,
            ],
        ];
    }

    /** @return array{ok:bool,text:string} */
    private function ok(array $data): array
    {
        return ['ok' => true, 'text' => (string) json_encode($data, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE)];
    }

    /** @return array{ok:bool,text:string} */
    private function fail(string $code, string $message): array
    {
        return ['ok' => false, 'text' => (string) json_encode(['error' => $code, 'message' => $message], JSON_UNESCAPED_UNICODE)];
    }
}
