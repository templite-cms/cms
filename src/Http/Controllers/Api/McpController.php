<?php

namespace Templite\Cms\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Templite\Cms\Services\Mcp\McpGateway;
use Templite\Cms\Services\Mcp\McpPrompts;
use Templite\Cms\Services\Mcp\McpScope;

/** MCP-шлюз для Node-прокси @templite/mcp-server. */
class McpController extends Controller
{
    public function __construct(
        private readonly McpGateway $gateway,
        private readonly McpPrompts $prompts,
    ) {
    }

    public function manifest(Request $request): JsonResponse
    {
        $manifest = $this->gateway->manifest($this->scope($request));

        return $this->success($manifest)->header('X-Mcp-Manifest-Hash', $manifest['hash']);
    }

    public function call(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => 'required|string|max:100',
            'arguments' => 'nullable|array',
        ]);

        $scope = $this->scope($request);
        $result = $this->gateway->call($scope, $data['name'], $data['arguments'] ?? []);

        // Хэш после вызова: прокси перечитает манифест, если набор изменился.
        return $this->success($result)->header('X-Mcp-Manifest-Hash', $this->gateway->manifest($scope)['hash']);
    }

    public function prompt(Request $request, string $name): JsonResponse
    {
        $args = $request->validate([
            'args' => 'nullable|array',
            'args.*' => 'nullable|string|max:5000',
        ])['args'] ?? [];

        $text = $this->prompts->render($name, $args, $this->scope($request)->profile);

        if ($text === null) {
            return $this->error("Сценария {$name} нет.", 404);
        }

        return $this->success(['text' => $text]);
    }

    private function scope(Request $request): McpScope
    {
        return $request->attributes->get('mcp.scope');
    }
}
