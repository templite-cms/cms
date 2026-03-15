<?php

namespace Templite\Cms\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Templite\Cms\Http\Resources\ManagerResource;
use Templite\Cms\Models\CmsConfig;
use Templite\Cms\Models\Manager;
use Templite\Cms\Models\ManagerLog;
use Templite\Cms\Models\ManagerSession;
use Templite\Cms\Services\TwoFactorService;

class TwoFactorController extends Controller
{
    public function __construct(
        protected TwoFactorService $twoFactor,
    ) {}

    /**
     * @OA\Post(
     *     path="/auth/two-factor/enable",
     *     summary="Включить 2FA — генерация секрета и QR",
     *     tags={"Auth"},
     *     security={{"sanctumAuth":{}}},
     *     @OA\Response(response=200, description="QR-код и секрет"),
     *     @OA\Response(response=400, description="2FA уже включена")
     * )
     */
    public function enable(): JsonResponse
    {
        /** @var Manager $manager */
        $manager = auth()->user();

        if ($manager->hasTwoFactorEnabled()) {
            return $this->error('Двухфакторная аутентификация уже включена.', 400);
        }

        $data = $this->twoFactor->generateSecret($manager);

        $this->logAction('two_factor_enable_started', 'auth', $manager->id);

        return $this->success([
            'secret' => $data['secret'],
            'qr_code' => $data['qr_code'],
        ]);
    }

    /**
     * @OA\Post(
     *     path="/auth/two-factor/confirm",
     *     summary="Подтвердить включение 2FA",
     *     tags={"Auth"},
     *     security={{"sanctumAuth":{}}},
     *     @OA\RequestBody(required=true, @OA\JsonContent(
     *         required={"code"},
     *         @OA\Property(property="code", type="string", example="123456")
     *     )),
     *     @OA\Response(response=200, description="2FA включена, recovery-коды"),
     *     @OA\Response(response=422, description="Неверный код")
     * )
     */
    public function confirm(Request $request): JsonResponse
    {
        $request->validate(['code' => 'required|string|size:6']);

        /** @var Manager $manager */
        $manager = auth()->user();

        if ($manager->hasTwoFactorEnabled()) {
            return $this->error('Двухфакторная аутентификация уже подтверждена.', 400);
        }

        if (!$this->twoFactor->confirmSetup($manager, $request->input('code'))) {
            return $this->error('Неверный код подтверждения.', 422);
        }

        $codes = $this->twoFactor->getRecoveryCodes($manager);

        $this->logAction('two_factor_enabled', 'auth', $manager->id);

        return $this->success([
            'recovery_codes' => $codes,
        ], 'Двухфакторная аутентификация включена.');
    }

    /**
     * @OA\Delete(
     *     path="/auth/two-factor/disable",
     *     summary="Отключить 2FA",
     *     tags={"Auth"},
     *     security={{"sanctumAuth":{}}},
     *     @OA\RequestBody(required=true, @OA\JsonContent(
     *         required={"password"},
     *         @OA\Property(property="password", type="string")
     *     )),
     *     @OA\Response(response=200, description="2FA отключена")
     * )
     */
    public function disable(Request $request): JsonResponse
    {
        $request->validate(['password' => 'required|string']);

        /** @var Manager $manager */
        $manager = auth()->user();

        if (!$manager->hasTwoFactorEnabled()) {
            return $this->error('Двухфакторная аутентификация не включена.', 400);
        }

        if (!Hash::check($request->input('password'), $manager->password)) {
            return $this->error('Неверный пароль.', 422);
        }

        $this->twoFactor->resetTwoFactor($manager);

        $this->logAction('two_factor_disabled', 'auth', $manager->id);

        return $this->success(null, 'Двухфакторная аутентификация отключена.');
    }

    /**
     * @OA\Post(
     *     path="/auth/two-factor/verify",
     *     summary="Второй шаг логина — проверка TOTP/recovery кода",
     *     tags={"Auth"},
     *     @OA\RequestBody(required=true, @OA\JsonContent(
     *         required={"two_factor_id", "code"},
     *         @OA\Property(property="two_factor_id", type="string"),
     *         @OA\Property(property="code", type="string"),
     *         @OA\Property(property="is_recovery", type="boolean", example=false),
     *         @OA\Property(property="trust_device", type="boolean", example=false),
     *         @OA\Property(property="remember", type="boolean", example=false)
     *     )),
     *     @OA\Response(response=200, description="Аутентификация успешна"),
     *     @OA\Response(response=401, description="Неверный код или токен")
     * )
     */
    public function verify(Request $request): JsonResponse
    {
        $request->validate([
            'two_factor_id' => 'required|string',
            'code' => 'required|string',
            'is_recovery' => 'boolean',
            'trust_device' => 'boolean',
            'remember' => 'boolean',
        ]);

        $manager = $this->twoFactor->validateTwoFactorId($request->input('two_factor_id'));
        if (!$manager) {
            return $this->error('Сессия 2FA истекла или недействительна. Повторите вход.', 401);
        }

        $code = $request->input('code');
        $isRecovery = $request->boolean('is_recovery', false);

        if ($isRecovery) {
            $valid = $this->twoFactor->useRecoveryCode($manager, $code);
        } else {
            $valid = $this->twoFactor->verifyCode($manager, $code);
        }

        if (!$valid) {
            return $this->error('Неверный код.', 401);
        }

        // Создаём полную сессию
        Auth::guard('manager')->login($manager, $request->boolean('remember', false));
        $request->session()->regenerate();

        // Создаём запись ManagerSession
        $remember = $request->boolean('remember', false);
        $sessionTtl = CmsConfig::getValue('auth.session_ttl', 60);
        $rememberTtl = CmsConfig::getValue('auth.remember_ttl', 20160);

        ManagerSession::create([
            'manager_id' => $manager->id,
            'token' => hash_hmac('sha256', $request->session()->getId(), config('app.key')),
            'user_agent' => $request->userAgent(),
            'ip' => $request->ip(),
            'last_active' => now(),
            'expires_at' => $remember
                ? now()->addMinutes($rememberTtl)
                : now()->addMinutes($sessionTtl),
        ]);

        // Trusted device cookie
        $response = $this->success([
            'user' => new ManagerResource($manager->load(['managerType', 'avatar'])),
        ], 'Аутентификация успешна.');

        if ($request->boolean('trust_device', false)) {
            $cookieValue = $this->twoFactor->createTrustedDevice($manager, $request);
            if ($cookieValue) {
                $cookieName = config('cms.two_factor.trust_cookie', 'cms_trusted_device');
                $trustDays = $this->twoFactor->getTrustDays();
                $secure = !app()->environment('local');
                $response->cookie($cookieName, $cookieValue, $trustDays * 24 * 60, '/', null, $secure, true, false, 'lax');
            }
        }

        // Лог
        ManagerLog::create([
            'manager_id' => $manager->id,
            'action' => $isRecovery ? 'two_factor_recovery_used' : 'login_with_two_factor',
            'entity_type' => 'auth',
            'entity_id' => $manager->id,
            'data' => ['login' => $manager->login, 'two_factor' => true],
            'ip' => $request->ip(),
        ]);

        return $response;
    }

    /**
     * @OA\Get(
     *     path="/auth/two-factor/recovery-codes",
     *     summary="Получить recovery-коды",
     *     tags={"Auth"},
     *     security={{"sanctumAuth":{}}},
     *     @OA\Response(response=200, description="Recovery-коды")
     * )
     */
    public function recoveryCodes(): JsonResponse
    {
        /** @var Manager $manager */
        $manager = auth()->user();

        if (!$manager->hasTwoFactorEnabled()) {
            return $this->error('Двухфакторная аутентификация не включена.', 400);
        }

        return $this->success([
            'recovery_codes' => $this->twoFactor->getRecoveryCodes($manager),
        ]);
    }

    /**
     * @OA\Post(
     *     path="/auth/two-factor/recovery-codes",
     *     summary="Перегенерировать recovery-коды",
     *     tags={"Auth"},
     *     security={{"sanctumAuth":{}}},
     *     @OA\Response(response=200, description="Новые recovery-коды")
     * )
     */
    public function regenerateRecoveryCodes(): JsonResponse
    {
        /** @var Manager $manager */
        $manager = auth()->user();

        if (!$manager->hasTwoFactorEnabled()) {
            return $this->error('Двухфакторная аутентификация не включена.', 400);
        }

        $codes = $this->twoFactor->regenerateRecoveryCodes($manager);

        $this->logAction('two_factor_recovery_regenerated', 'auth', $manager->id);

        return $this->success(['recovery_codes' => $codes], 'Recovery-коды перегенерированы.');
    }

    /**
     * @OA\Delete(
     *     path="/managers/{id}/two-factor",
     *     summary="Сбросить 2FA менеджеру (админ)",
     *     tags={"Managers"},
     *     security={{"sanctumAuth":{}}},
     *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
     *     @OA\Response(response=200, description="2FA сброшена")
     * )
     */
    public function resetForManager(int $id): JsonResponse
    {
        $manager = Manager::findOrFail($id);
        $this->twoFactor->resetTwoFactor($manager);

        $this->logAction('two_factor_reset_by_admin', 'manager', $manager->id, [
            'reset_by' => auth()->id(),
        ]);

        return $this->success(null, 'Двухфакторная аутентификация сброшена.');
    }
}
