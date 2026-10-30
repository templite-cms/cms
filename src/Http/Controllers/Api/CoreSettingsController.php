<?php

namespace Templite\Cms\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Templite\Cms\Models\CmsConfig;

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

        // Медиа: допустимые расширения файлов
        $defaultExtensions = collect(config('cms.allowed_file_types', []))
            ->flatten()->unique()->sort()->values()->implode(', ');

        CmsConfig::firstOrCreate(
            ['key' => 'allowed_file_extensions'],
            [
                'value' => $defaultExtensions,
                'type' => 'string',
                'group' => 'media',
                'label' => 'Разрешённые расширения файлов',
                'description' => 'Расширения через запятую (например: jpg, png, pdf, doc). Загрузка файлов с расширениями не из этого списка будет отклонена.',
                'order' => 20,
            ]
        );

        // MCP-доступ. updateOrCreate без value: старая запись из group=mcp
        // переезжает в «Систему» и сохраняет значение.
        CmsConfig::updateOrCreate(
            ['key' => 'mcp.enabled'],
            [
                'type' => 'boolean',
                'group' => 'system',
                'label' => 'MCP-доступ',
                'description' => 'Разрешить внешним AI-клиентам (Claude Code, Cursor и др.) работать с сайтом через @templite/mcp-server. Токены менеджеры выпускают в своём профиле.',
                'order' => 50,
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
}
