<?php

namespace Templite\Cms\Services\Ai;

use Templite\Cms\Models\CmsConfig;

/**
 * Общесайтовые настройки ассистента: включён ли он и системный промпт.
 *
 * Ключи, модели и лимиты живут на AiKey — они принадлежат менеджеру,
 * а не установке CMS.
 */
class AiSettingsService
{
    public const DEFAULT_PROMPT = 'Ты — встроенный в CMS Templite ассистент. Помогаешь владельцу сайта: создаёшь и редактируешь блоки, наполняешь контент, создаёшь страницы. Перед любой задачей собирай контекст доступными read-инструментами, затем выполняй изменения write-инструментами. Действуй пошагово, объясняй кратко.';

    public function isEnabled(): bool
    {
        return (bool) CmsConfig::getValue('ai.enabled', false);
    }

    public function systemPrompt(): string
    {
        return (string) CmsConfig::getValue('ai.system_prompt', self::DEFAULT_PROMPT) ?: self::DEFAULT_PROMPT;
    }

    public function save(bool $enabled, ?string $systemPrompt = null): void
    {
        $this->put('ai.enabled', $enabled ? '1' : '0', 'boolean');

        if ($systemPrompt !== null) {
            $this->put('ai.system_prompt', $systemPrompt, 'string');
        }
    }

    private function put(string $key, ?string $value, string $type): void
    {
        $config = CmsConfig::where('key', $key)->first();

        if ($config) {
            $config->update(['value' => $value]);
        } else {
            CmsConfig::create([
                'key' => $key,
                'value' => $value,
                'type' => $type,
                'group' => 'ai',
                'label' => $key,
            ]);
        }

        CmsConfig::clearCache();
    }
}
