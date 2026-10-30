<?php

namespace Templite\Cms\Http\Controllers\Api\Ai;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Templite\Cms\Http\Controllers\Api\Controller;
use Templite\Cms\Models\Manager;
use Templite\Cms\Services\Ai\AiKeyService;
use Templite\Cms\Services\Ai\AiSettingsService;
use Templite\Cms\Services\Ai\OpenRouterClient;

class AiSettingsController extends Controller
{
    public function __construct(
        private readonly AiSettingsService $settings,
        private readonly AiKeyService $keys,
    ) {
    }

    public function show(): JsonResponse
    {
        return $this->success([
            'enabled' => $this->settings->isEnabled(),
            'system_prompt' => $this->settings->systemPrompt(),
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'enabled' => ['required', 'boolean'],
            'system_prompt' => ['nullable', 'string', 'max:8000'],
        ]);

        $this->settings->save(
            enabled: $data['enabled'],
            systemPrompt: $data['system_prompt'] ?? null,
        );

        $this->logAction('update', 'ai_settings', null, ['enabled' => $data['enabled']]);

        return $this->success(['saved' => true], 'Настройки сохранены.');
    }

    /** Остаток средств по рабочему ключу — его показывает счётчик в шапке. */
    public function status(): JsonResponse
    {
        $manager = $this->manager();
        $key = $this->keys->activeFor($manager);

        if (! $this->keys->canUse($manager) || ! $key || $key->provider !== 'openrouter') {
            return $this->success([
                'enabled' => $this->settings->isEnabled(),
                'key_id' => $key?->id,
                'balance' => null,
            ]);
        }

        return $this->success([
            'enabled' => true,
            'key_id' => $key->id,
            'balance' => OpenRouterClient::forKey($key)->credits(),
        ]);
    }

    /**
     * Модели провайдера: по умолчанию — рабочего ключа, но форма ключа
     * спрашивает список под тот ключ, который сейчас правят.
     */
    public function models(Request $request): JsonResponse
    {
        $manager = $this->manager();
        $keyId = (int) $request->input('key_id');

        $key = $keyId > 0
            ? $this->keys->availableFor($manager)->firstWhere('id', $keyId)
            : $this->keys->activeFor($manager);

        if ($key && $key->provider !== 'openrouter') {
            return $this->success(['chat' => [], 'image' => []]);
        }

        $client = OpenRouterClient::catalog();

        return $this->success([
            'chat' => $client->toolModels(),
            'image' => $client->imageModels(),
        ]);
    }

    private function manager(): Manager
    {
        return auth()->user() ?? auth('manager')->user();
    }
}
