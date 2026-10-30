<?php

namespace Templite\Cms\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;
use Templite\Cms\Services\Mcp\McpScope;
use Templite\Cms\Services\Mcp\McpSettings;

/**
 * Шлюз пускает только личный MCP-токен: сессия админки и обычные API-токены
 * не подходят — у них нет профиля и abilities MCP.
 */
class EnsureMcpToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->user()?->currentAccessToken();

        if (! $token instanceof PersonalAccessToken || ! McpScope::isMcpToken($token)) {
            return response()->json([
                'success' => false,
                'code' => 'mcp_token_required',
                'message' => 'Нужен MCP-токен: выпустите его в профиле менеджера CMS.',
            ], 403);
        }

        // Право сняли — уже выпущенные токены перестают работать сразу,
        // а не досрочно истекают через месяцы
        if (! $request->user()->hasPermission('mcp.use')) {
            return response()->json([
                'success' => false,
                'code' => 'mcp_forbidden',
                'message' => 'У владельца токена нет права «Доступ через MCP».',
            ], 403);
        }

        if (! McpSettings::enabled()) {
            return response()->json([
                'success' => false,
                'code' => 'mcp_disabled',
                'message' => 'MCP-доступ выключен: включите его в настройках ядра CMS (карточка «Система»).',
            ], 403);
        }

        $request->attributes->set('mcp.scope', McpScope::fromToken(
            $token,
            $request->header('X-Templite-Profile'),
            $request->header('X-Mcp-Mode'),
        ));
        $request->attributes->set('cms.channel', 'mcp');

        return $next($request);
    }
}
