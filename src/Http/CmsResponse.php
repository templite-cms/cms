<?php

namespace Templite\Cms\Http;

use Illuminate\Http\Response;
use Templite\Cms\Models\CmsConfig;
use Templite\Cms\Models\Language;
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
            'multicity_enabled' => (bool) CmsConfig::getValue('multicity_enabled', false),
            'two_factor_mode' => CmsConfig::getValue('two_factor_mode', config('cms.two_factor.mode', 'off')),
            'two_factor_trust_days' => (int) CmsConfig::getValue('two_factor_trust_days', config('cms.two_factor.trust_days', 0)),
            'multilang_enabled' => (bool) CmsConfig::getValue('multilang_enabled', false),
            'languages' => CmsConfig::getValue('multilang_enabled', false)
                ? Language::active()->ordered()->get()->map(fn ($l) => [
                    'id' => $l->id,
                    'code' => $l->code,
                    'name' => $l->name,
                    'is_default' => $l->is_default,
                ])
                : [],
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
     * Render a cabinet page (user personal area) without admin header.
     */
    public static function cabinet(string $entry, array $props = [], array $meta = []): Response
    {
        return response()->view('cms::layouts.cabinet', [
            'pageEntry' => $entry,
            'pageProps' => $props,
            'pageTitle' => $meta['title'] ?? null,
            'pageAssets' => $meta['assets'] ?? [],
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
