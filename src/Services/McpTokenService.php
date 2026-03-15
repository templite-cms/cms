<?php

namespace Templite\Cms\Services;

use Illuminate\Support\Facades\Log;
use Templite\Cms\Models\CmsConfig;
use Templite\Cms\Models\Manager;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Управление MCP-токеном через Sanctum personal access tokens.
 *
 * Хранит ID текущего MCP-токена и последние 4 символа в cms_config.
 * Токен ограничен конкретным набором abilities и обязательным сроком истечения.
 */
class McpTokenService
{
    /**
     * Допустимые abilities для MCP-токена.
     * Не включает управление менеджерами, логами и критическими настройками.
     */
    protected const MCP_ABILITIES = [
        'blocks:read', 'blocks:write',
        'pages:read', 'pages:write',
        'templates:read', 'templates:write',
        'components:read', 'components:write',
        'settings:read',
        'files:read', 'files:write',
    ];

    /**
     * Срок действия токена по умолчанию (в днях).
     */
    protected const DEFAULT_EXPIRATION_DAYS = 30;

    public function isEnabled(): bool
    {
        return (bool) CmsConfig::getValue('mcp.enabled', false);
    }

    public function setEnabled(bool $enabled): void
    {
        $this->ensureConfigKey('mcp.enabled', $enabled ? '1' : '0', 'boolean');

        Log::info('MCP status changed', [
            'enabled' => $enabled,
        ]);
    }

    /**
     * Возвращает замаскированный токен (последние 4 символа) или null.
     */
    public function getMaskedToken(): ?string
    {
        $suffix = $this->getConfigValue('mcp.token_suffix');

        return $suffix ? '***' . $suffix : null;
    }

    public function getTokenExpiresAt(): ?string
    {
        $tokenId = $this->getConfigValue('mcp.token_id');

        if (!$tokenId) {
            return null;
        }

        $token = PersonalAccessToken::find($tokenId);

        return $token?->expires_at?->toIso8601String();
    }

    public function setTokenExpiresAt(?string $expiresAt): void
    {
        $tokenId = $this->getConfigValue('mcp.token_id');

        if (!$tokenId) {
            return;
        }

        $token = PersonalAccessToken::find($tokenId);

        if ($token) {
            // Если expiresAt не указан, ставим default 30 дней от текущего момента
            $newExpiresAt = $expiresAt ?: now()->addDays(self::DEFAULT_EXPIRATION_DAYS)->toIso8601String();
            $token->update(['expires_at' => $newExpiresAt]);

            Log::info('MCP token expiration updated', [
                'token_id' => $tokenId,
                'expires_at' => $newExpiresAt,
            ]);
        }
    }

    /**
     * Возвращает список abilities, назначаемых MCP-токену.
     */
    public function getAbilities(): array
    {
        return self::MCP_ABILITIES;
    }

    /**
     * Генерирует новый MCP-токен. Старый отзывается.
     * Токен создаётся для первого суперадмина.
     *
     * @return string Plain text токен (показывается один раз)
     */
    public function generateToken(): string
    {
        // Отзываем старый, если есть
        $this->revokeToken();

        $manager = $this->getSystemManager();

        $expiresAt = $this->getConfigValue('mcp.token_expires_at');

        // Обязательный срок истечения: из конфига или default 30 дней
        $expiration = $expiresAt
            ? now()->parse($expiresAt)
            : now()->addDays(self::DEFAULT_EXPIRATION_DAYS);

        $token = $manager->createToken(
            'MCP Server',
            self::MCP_ABILITIES,
            $expiration,
        );

        $plainToken = $token->plainTextToken;

        // Сохраняем ID и последние 4 символа для отображения
        $this->ensureConfigKey('mcp.token_id', (string) $token->accessToken->id, 'string');
        $this->ensureConfigKey('mcp.token_suffix', substr($plainToken, -4), 'string');

        Log::info('MCP token generated', [
            'manager_id' => $manager->id,
            'manager_login' => $manager->login,
            'token_id' => $token->accessToken->id,
            'abilities' => self::MCP_ABILITIES,
            'expires_at' => $expiration->toIso8601String(),
        ]);

        return $plainToken;
    }

    /**
     * Отзывает текущий MCP-токен.
     */
    public function revokeToken(): void
    {
        $tokenId = $this->getConfigValue('mcp.token_id');

        if ($tokenId) {
            $token = PersonalAccessToken::find($tokenId);
            if ($token) {
                $token->delete();

                Log::info('MCP token revoked', [
                    'token_id' => $tokenId,
                ]);
            }
        }

        $this->ensureConfigKey('mcp.token_id', null, 'string');
        $this->ensureConfigKey('mcp.token_suffix', null, 'string');
    }

    /**
     * Находит менеджера-суперадмина для привязки токена.
     */
    private function getSystemManager(): Manager
    {
        // Берём первого менеджера с правами ['*'] (суперадмин)
        $manager = Manager::whereHas('managerType', function ($query) {
            $query->whereJsonContains('permissions', '*');
        })->first();

        if (!$manager) {
            $manager = Manager::first();
        }

        if (!$manager) {
            throw new \RuntimeException('Нет менеджеров для создания MCP-токена.');
        }

        return $manager;
    }

    private function getConfigValue(string $key): ?string
    {
        $config = CmsConfig::where('key', $key)->first();

        return $config?->value;
    }

    /**
     * Гарантирует наличие записи в cms_config и устанавливает значение.
     */
    private function ensureConfigKey(string $key, ?string $value, string $type = 'string'): void
    {
        $config = CmsConfig::where('key', $key)->first();

        if ($config) {
            $config->update(['value' => $value]);
        } else {
            CmsConfig::create([
                'key' => $key,
                'value' => $value,
                'type' => $type,
                'group' => 'mcp',
                'label' => $key,
            ]);
        }
    }
}
