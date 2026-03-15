<?php

namespace Templite\Cms\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Templite\Cms\Models\CmsConfig;
use Templite\Cms\Services\McpTokenService;

class CoreSettingsController extends Controller
{
    /**
     * @OA\Get(
     *     path="/core-settings",
     *     summary="Получить настройки ядра CMS",
     *     tags={"Core Settings"},
     *     security={{"bearerAuth":{}}},
     *     @OA\Response(response=200, description="Настройки сгруппированные по group")
     * )
     */
    public function index(): JsonResponse
    {
        // Ensure settings exist
        CmsConfig::firstOrCreate(
            ['key' => 'admin_url'],
            [
                'value' => null,
                'type' => 'string',
                'group' => 'system',
                'label' => 'URL админки',
                'description' => 'Префикс URL админки (например: cms, admin). Если пусто — используется значение из ENV.',
                'order' => 0,
            ]
        );

        // 2FA settings
        CmsConfig::firstOrCreate(
            ['key' => 'two_factor_mode'],
            [
                'value' => 'off',
                'type' => 'select',
                'group' => 'auth',
                'label' => 'Двухфакторная аутентификация',
                'description' => 'Режим 2FA: выключена, по желанию менеджера или обязательная для всех.',
                'order' => 10,
            ]
        );

        CmsConfig::firstOrCreate(
            ['key' => 'two_factor_trust_days'],
            [
                'value' => '0',
                'type' => 'integer',
                'group' => 'auth',
                'label' => 'Доверие устройству (дней)',
                'description' => '0 — спрашивать код при каждом входе. Больше 0 — запоминать устройство на указанное количество дней.',
                'order' => 11,
            ]
        );

        $settings = CmsConfig::orderBy('order')->get();

        $grouped = $settings->groupBy('group')->map(function ($items) {
            return $items->values();
        });

        return $this->success([
            'settings' => $settings,
            'grouped' => $grouped,
        ]);
    }

    /**
     * @OA\Put(
     *     path="/core-settings",
     *     summary="Массовое обновление настроек ядра",
     *     tags={"Core Settings"},
     *     security={{"bearerAuth":{}}},
     *     @OA\RequestBody(required=true, @OA\JsonContent(
     *         @OA\Property(property="values", type="object")
     *     )),
     *     @OA\Response(response=200, description="Настройки сохранены")
     * )
     */
    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'values' => 'required|array',
        ]);

        foreach ($data['values'] as $key => $value) {
            CmsConfig::setValue($key, $value);
        }

        // Страховка: сбросить кэш после массового обновления
        CmsConfig::clearCache();

        if (array_key_exists('admin_url', $data['values'])) {
            \Illuminate\Support\Facades\Artisan::call('route:clear');
        }

        $this->logAction('update', 'core_settings', null, ['keys' => array_keys($data['values'])]);

        return $this->success(null, 'Настройки сохранены');
    }

    /**
     * @OA\Get(
     *     path="/core-settings/mcp",
     *     summary="Получить настройки MCP",
     *     tags={"Core Settings"},
     *     security={{"bearerAuth":{}}},
     *     @OA\Response(response=200, description="Настройки MCP")
     * )
     */
    public function mcpSettings(): JsonResponse
    {
        $tokenService = app(McpTokenService::class);

        return $this->success([
            'enabled' => $tokenService->isEnabled(),
            'has_token' => $tokenService->getMaskedToken() !== null,
            'masked_token' => $tokenService->getMaskedToken(),
            'token_expires_at' => $tokenService->getTokenExpiresAt(),
        ]);
    }

    /**
     * @OA\Put(
     *     path="/core-settings/mcp",
     *     summary="Обновить настройки MCP",
     *     tags={"Core Settings"},
     *     security={{"bearerAuth":{}}},
     *     @OA\RequestBody(required=true, @OA\JsonContent(
     *         @OA\Property(property="enabled", type="boolean"),
     *         @OA\Property(property="token_expires_at", type="string", format="date-time", nullable=true)
     *     )),
     *     @OA\Response(response=200, description="Настройки обновлены")
     * )
     */
    public function updateMcpSettings(Request $request): JsonResponse
    {
        $data = $request->validate([
            'enabled' => 'sometimes|boolean',
            'token_expires_at' => 'sometimes|nullable|date',
        ]);

        $tokenService = app(McpTokenService::class);

        if (array_key_exists('enabled', $data)) {
            $tokenService->setEnabled($data['enabled']);
        }

        if (array_key_exists('token_expires_at', $data)) {
            $tokenService->setTokenExpiresAt($data['token_expires_at']);
        }

        $this->logAction('update', 'core_settings', null, ['entity' => 'mcp', 'enabled' => $data['enabled'] ?? null]);

        return $this->success(null, 'MCP настройки обновлены');
    }

    /**
     * @OA\Post(
     *     path="/core-settings/mcp/generate-token",
     *     summary="Сгенерировать новый MCP-токен",
     *     tags={"Core Settings"},
     *     security={{"bearerAuth":{}}},
     *     @OA\Response(response=200, description="Токен сгенерирован")
     * )
     */
    public function generateMcpToken(): JsonResponse
    {
        $tokenService = app(McpTokenService::class);
        $plainToken = $tokenService->generateToken();

        $this->logAction('create', 'core_settings', null, ['entity' => 'mcp_token']);

        return $this->success([
            'token' => $plainToken,
            'message' => 'Скопируйте токен. Он будет показан только один раз.',
        ]);
    }

    /**
     * @OA\Delete(
     *     path="/core-settings/mcp/revoke-token",
     *     summary="Отозвать MCP-токен",
     *     tags={"Core Settings"},
     *     security={{"bearerAuth":{}}},
     *     @OA\Response(response=200, description="Токен отозван")
     * )
     */
    public function revokeMcpToken(): JsonResponse
    {
        $tokenService = app(McpTokenService::class);
        $tokenService->revokeToken();

        $this->logAction('delete', 'core_settings', null, ['entity' => 'mcp_token']);

        return $this->success(null, 'Токен отозван');
    }
}
