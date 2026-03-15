<?php

namespace Templite\Cms\Http\Controllers\Admin;

use Illuminate\Routing\Controller;
use Inertia\Inertia;
use Inertia\Response;
use Templite\Cms\Models\City;
use Templite\Cms\Models\CmsConfig;
use Templite\Cms\Models\Language;

class CoreSettingsController extends Controller
{
    /**
     * Страница настроек ядра CMS.
     */
    public function index(): Response
    {
        // Ensure default settings exist
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

        $cities = City::orderBy('sort_order')->orderBy('name')->get();
        $languages = Language::orderBy('order')->get();

        return Inertia::render('CoreSettings/Index', [
            'settings' => $settings,
            'grouped' => $grouped,
            'cities' => $cities,
            'languages' => $languages,
        ]);
    }
}
