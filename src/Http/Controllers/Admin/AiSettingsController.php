<?php

namespace Templite\Cms\Http\Controllers\Admin;

use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Templite\Cms\Http\CmsResponse;
use Templite\Cms\Models\Manager;
use Templite\Cms\Services\Ai\AiKeyService;
use Templite\Cms\Services\Ai\AiSettingsService;

class AiSettingsController extends Controller
{
    /**
     * Страница ассистента: рабочий ключ, свои ключи с доступами и общие настройки.
     * Каждый блок отрисовывается по своему праву — их и отдаём странице.
     */
    public function index(AiSettingsService $settings, AiKeyService $keys): Response
    {
        /** @var Manager $manager */
        $manager = auth('manager')->user();

        return CmsResponse::page(
            'packages/templite/cms/resources/js/entries/ai-settings.js',
            [
                'settings' => [
                    'enabled' => $settings->isEnabled(),
                    'system_prompt' => $settings->systemPrompt(),
                ],
                'can' => [
                    'manage_keys' => $manager->hasPermission('ai.keys.manage'),
                    'edit_settings' => $manager->hasPermission('settings.edit'),
                    'view_settings' => $manager->hasPermission('settings.view'),
                    'admin' => $manager->hasPermission('*'),
                ],
                'active_key_id' => $keys->activeFor($manager)?->id,
            ],
            ['title' => 'AI-ассистент']
        );
    }
}
