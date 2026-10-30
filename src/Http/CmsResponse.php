<?php

namespace Templite\Cms\Http;

use Illuminate\Http\Response;
use Templite\Cms\Models\CmsConfig;
use Templite\Cms\Services\ModuleRegistry;

class CmsResponse
{
    /**
     * Render an admin page with header island and Vue page entry.
     */
    public static function page(?string $entry, array $props = [], array $meta = []): Response
    {
        $user = auth('manager')->user();
        $navigation = app(ModuleRegistry::class)->getNavigation($user);

        $cmsConfig = [
            'admin_url' => '/' . ltrim(CmsConfig::getAdminUrl(), '/'),
            'two_factor_mode' => CmsConfig::getValue('two_factor_mode', config('cms.two_factor.mode', 'off')),
            'two_factor_trust_days' => (int) CmsConfig::getValue('two_factor_trust_days', config('cms.two_factor.trust_days', 0)),
        ];

        $keys = app(\Templite\Cms\Services\Ai\AiKeyService::class);
        $activeKey = $user ? $keys->activeFor($user) : null;

        $canUseAi = $user ? $keys->canUse($user) : false;

        // Панель ассистента — остров на каждой странице. Флаг только прячет UI:
        // доступ всё равно проверяют маршруты /ai/*.
        $cmsConfig['ai'] = [
            'enabled' => app(\Templite\Cms\Services\Ai\AiSettingsService::class)->isEnabled(),
            'can_use' => $canUseAi,
            'has_key' => (bool) $activeKey,
            // Ход агента идёт в фоне — остров чата подключится к нему сразу, даже
            // с закрытой панелью: правкам редактора и индикатору в шапке она не нужна.
            'active_run' => $canUseAi
                ? \Templite\Cms\Models\AiRun::where('manager_id', $user->id)
                    ->whereIn('status', \Templite\Cms\Models\AiRun::ACTIVE)
                    ->latest('id')
                    ->first()?->brief()
                : null,
        ];

        // Карточка MCP-токенов в профиле. Флаг только прячет UI — маршруты /mcp-tokens проверяют право сами.
        $cmsConfig['mcp'] = [
            'can_use' => $user ? $user->hasPermission('mcp.use') : false,
        ];

        return response()->view('cms::layouts.admin', [
            'pageEntry' => $entry,
            'pageProps' => $props,
            'pageTitle' => $meta['title'] ?? null,
            'pageAssets' => $meta['assets'] ?? [],
            'navigation' => $navigation,
            'user' => $user,
            'cmsConfig' => $cmsConfig,
            'currentUrl' => request()->url(),
        ]);
    }

    /**
     * Render a guest page (login, 2FA) without header island.
     */
    public static function guest(string $entry, array $props = []): Response
    {
        return response()->view('cms::layouts.guest', [
            'pageEntry' => $entry,
            'pageProps' => $props,
        ]);
    }
}
