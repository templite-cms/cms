<?php

namespace Templite\Cms\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use PragmaRX\Google2FA\Google2FA;
use Templite\Cms\Models\Manager;
use Templite\Cms\Models\ManagerTrustedDevice;
use Templite\Cms\Models\CmsConfig;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;

class TwoFactorService
{
    protected Google2FA $google2fa;

    public function __construct()
    {
        $this->google2fa = new Google2FA();
    }

    /**
     * Генерация секрета и QR-кода для настройки 2FA.
     */
    public function generateSecret(Manager $manager): array
    {
        $secret = $this->google2fa->generateSecretKey();
        $appName = config('cms.name', 'Templite CMS');
        $otpauthUrl = $this->google2fa->getQRCodeUrl($appName, $manager->login, $secret);

        // QR как SVG -> base64
        $renderer = new ImageRenderer(
            new RendererStyle(200),
            new SvgImageBackEnd()
        );
        $writer = new Writer($renderer);
        $qrSvg = $writer->writeString($otpauthUrl);
        $qrBase64 = 'data:image/svg+xml;base64,' . base64_encode($qrSvg);

        // Сохраняем зашифрованный секрет (не подтверждённый)
        $manager->forceFill([
            'two_factor_secret' => Crypt::encryptString($secret),
            'two_factor_confirmed_at' => null,
        ])->save();

        return [
            'secret' => $secret,
            'qr_code' => $qrBase64,
            'otpauth_url' => $otpauthUrl,
        ];
    }

    /**
     * Подтверждение настройки 2FA (пользователь ввёл код из приложения).
     */
    public function confirmSetup(Manager $manager, string $code): bool
    {
        $secret = $this->getDecryptedSecret($manager);
        if (!$secret) {
            return false;
        }

        $window = config('cms.two_factor.totp_window', 1);
        $valid = $this->google2fa->verifyKey($secret, $code, $window);

        if ($valid) {
            $recoveryCodes = $this->generateRecoveryCodes();
            $manager->forceFill([
                'two_factor_confirmed_at' => now(),
                'two_factor_recovery_codes' => Crypt::encryptString(json_encode($recoveryCodes)),
            ])->save();

            return true;
        }

        return false;
    }

    /**
     * Проверка TOTP-кода.
     */
    public function verifyCode(Manager $manager, string $code): bool
    {
        $secret = $this->getDecryptedSecret($manager);
        if (!$secret) {
            return false;
        }

        $window = config('cms.two_factor.totp_window', 1);

        return $this->google2fa->verifyKey($secret, $code, $window);
    }

    /**
     * Проверка и использование recovery-кода.
     */
    public function useRecoveryCode(Manager $manager, string $code): bool
    {
        $codes = $this->getDecryptedRecoveryCodes($manager);
        if (empty($codes)) {
            return false;
        }

        $code = strtoupper(trim($code));
        $index = array_search($code, $codes, true);

        if ($index === false) {
            return false;
        }

        // Удаляем использованный код
        unset($codes[$index]);
        $manager->forceFill([
            'two_factor_recovery_codes' => Crypt::encryptString(json_encode(array_values($codes))),
        ])->save();

        return true;
    }

    /**
     * Генерация 8 recovery-кодов формата XXXX-XXXX.
     */
    public function generateRecoveryCodes(): array
    {
        $codes = [];
        for ($i = 0; $i < 8; $i++) {
            $codes[] = strtoupper(Str::random(4)) . '-' . strtoupper(Str::random(4));
        }
        return $codes;
    }

    /**
     * Перегенерация recovery-кодов.
     */
    public function regenerateRecoveryCodes(Manager $manager): array
    {
        $codes = $this->generateRecoveryCodes();
        $manager->forceFill([
            'two_factor_recovery_codes' => Crypt::encryptString(json_encode($codes)),
        ])->save();

        return $codes;
    }

    /**
     * Получить текущие recovery-коды.
     */
    public function getRecoveryCodes(Manager $manager): array
    {
        return $this->getDecryptedRecoveryCodes($manager);
    }

    /**
     * Сброс 2FA (удаление секрета, кодов, доверенных устройств).
     */
    public function resetTwoFactor(Manager $manager): void
    {
        $manager->forceFill([
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
        ])->save();

        $manager->trustedDevices()->delete();
    }

    // --- Trusted Devices ---

    /**
     * Создать доверенное устройство и вернуть значение для cookie.
     */
    public function createTrustedDevice(Manager $manager, Request $request): string
    {
        $trustDays = $this->getTrustDays();
        if ($trustDays <= 0) {
            return '';
        }

        $cookieValue = Str::random(64);
        $hashedToken = hash('sha256', $cookieValue);

        ManagerTrustedDevice::create([
            'manager_id' => $manager->id,
            'token' => $hashedToken,
            'user_agent' => substr($request->userAgent() ?? '', 0, 255),
            'ip' => $request->ip(),
            'last_used_at' => now(),
            'expires_at' => now()->addDays($trustDays),
        ]);

        return $cookieValue;
    }

    /**
     * Проверить, является ли устройство доверенным.
     */
    public function isTrustedDevice(Manager $manager, Request $request): bool
    {
        $trustDays = $this->getTrustDays();
        if ($trustDays <= 0) {
            return false;
        }

        $cookieName = config('cms.two_factor.trust_cookie', 'cms_trusted_device');
        $cookieValue = $request->cookie($cookieName);

        if (!$cookieValue) {
            return false;
        }

        $hashedToken = hash('sha256', $cookieValue);
        $device = $manager->trustedDevices()
            ->valid()
            ->where('token', $hashedToken)
            ->first();

        if ($device) {
            $device->update(['last_used_at' => now()]);
            return true;
        }

        return false;
    }

    /**
     * Удалить просроченные доверенные устройства.
     */
    public function cleanupExpiredDevices(): int
    {
        return ManagerTrustedDevice::expired()->delete();
    }

    // --- Two Factor ID (для двухшагового логина) ---

    /**
     * Создать зашифрованный two_factor_id (одноразовый, TTL 5 мин).
     */
    public function createTwoFactorId(Manager $manager): string
    {
        $nonce = Str::random(32);
        $payload = $manager->id . ':' . $nonce . ':' . time();

        // Сохраняем nonce в кэше (одноразовый)
        Cache::put("two_factor_nonce:{$nonce}", $manager->id, 300);

        return Crypt::encryptString($payload);
    }

    /**
     * Расшифровать и валидировать two_factor_id. Возвращает Manager или null.
     */
    public function validateTwoFactorId(string $twoFactorId): ?Manager
    {
        try {
            $payload = Crypt::decryptString($twoFactorId);
        } catch (\Throwable) {
            return null;
        }

        $parts = explode(':', $payload);
        if (count($parts) !== 3) {
            return null;
        }

        [$managerId, $nonce, $timestamp] = $parts;

        // Проверка TTL (5 минут)
        if ((time() - (int) $timestamp) > 300) {
            Cache::forget("two_factor_nonce:{$nonce}");
            return null;
        }

        // Проверка одноразовости
        $cachedManagerId = Cache::pull("two_factor_nonce:{$nonce}");
        if ($cachedManagerId === null || (int) $cachedManagerId !== (int) $managerId) {
            return null;
        }

        $manager = Manager::find($managerId);
        if (!$manager || !$manager->is_active) {
            return null;
        }

        return $manager;
    }

    // --- Helpers ---

    /**
     * Текущий режим 2FA: 'off', 'optional', 'required'.
     */
    public function getMode(): string
    {
        return CmsConfig::getValue('two_factor_mode', config('cms.two_factor.mode', 'off'));
    }

    /**
     * Включена ли 2FA (mode != 'off').
     */
    public function isEnabled(): bool
    {
        return $this->getMode() !== 'off';
    }

    /**
     * Количество дней доверия устройству.
     */
    public function getTrustDays(): int
    {
        return (int) CmsConfig::getValue('two_factor_trust_days', config('cms.two_factor.trust_days', 0));
    }

    protected function getDecryptedSecret(Manager $manager): ?string
    {
        if (!$manager->two_factor_secret) {
            return null;
        }

        try {
            return Crypt::decryptString($manager->two_factor_secret);
        } catch (\Throwable) {
            return null;
        }
    }

    protected function getDecryptedRecoveryCodes(Manager $manager): array
    {
        if (!$manager->two_factor_recovery_codes) {
            return [];
        }

        try {
            $decrypted = Crypt::decryptString($manager->two_factor_recovery_codes);
            return json_decode($decrypted, true) ?? [];
        } catch (\Throwable) {
            return [];
        }
    }
}
