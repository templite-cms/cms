<?php

namespace Templite\Cms\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Templite\Cms\Http\Resources\ManagerResource;
use Templite\Cms\Models\CmsConfig;
use Templite\Cms\Models\Manager;
use Templite\Cms\Models\ManagerSession;
use Templite\Cms\Services\TwoFactorService;

class AuthController extends Controller
{
    /**
     * @OA\Post(
     *     path="/auth/login",
     *     summary="Авторизация (сессионная)",
     *     tags={"Auth"},
     *     @OA\RequestBody(required=true, @OA\JsonContent(
     *         required={"login","password"},
     *         @OA\Property(property="login", type="string"),
     *         @OA\Property(property="password", type="string"),
     *         @OA\Property(property="remember", type="boolean", example=false)
     *     )),
     *     @OA\Response(response=200, description="Авторизация успешна", @OA\JsonContent(
     *         @OA\Property(property="success", type="boolean", example=true),
     *         @OA\Property(property="message", type="string", example="Авторизация успешна."),
     *         @OA\Property(property="data", type="object",
     *             @OA\Property(property="user", ref="#/components/schemas/ManagerResource"),
     *             @OA\Property(property="force_password_change", type="boolean", example=false, description="Требуется смена пароля по умолчанию")
     *         )
     *     )),
     *     @OA\Response(response=401, description="Неверные учётные данные")
     * )
     */
    public function login(Request $request): JsonResponse
    {
        $credentials = $request->validate([
            'login' => 'required|string',
            'password' => 'required|string',
        ]);

        $remember = $request->boolean('remember', false);
        $sessionTtl = CmsConfig::getValue('auth.session_ttl', 60);
        $rememberTtl = CmsConfig::getValue('auth.remember_ttl', 20160);

        // Находим менеджера и проверяем пароль вручную (без создания сессии)
        $manager = Manager::where('login', $credentials['login'])->first();

        if (!$manager || !Hash::check($credentials['password'], $manager->password)) {
            \Log::warning('Failed login attempt', [
                'login' => $request->input('login'),
                'ip' => $request->ip(),
                'user_agent' => $request->userAgent(),
            ]);
            return $this->error('Неверный логин или пароль.', 401);
        }

        // Проверка активности
        if (!$manager->is_active) {
            return $this->error('Аккаунт деактивирован.', 403);
        }

        // Проверка 2FA
        $twoFactor = app(TwoFactorService::class);
        if ($manager->hasTwoFactorEnabled() && $twoFactor->isEnabled()) {
            // Проверяем доверенное устройство
            if (!$twoFactor->isTrustedDevice($manager, $request)) {
                $twoFactorId = $twoFactor->createTwoFactorId($manager);

                return $this->success([
                    'two_factor_required' => true,
                    'two_factor_id' => $twoFactorId,
                ], 'Требуется двухфакторная аутентификация.');
            }
        }

        // Создаём полную сессию (2FA не нужна или устройство доверенное)
        Auth::guard('manager')->login($manager, $remember);
        $request->session()->regenerate();

        $ttl = $remember ? $rememberTtl : $sessionTtl;

        // Чистим просроченные сессии
        ManagerSession::where('manager_id', $manager->id)->expired()->delete();

        // Создаём сессию
        ManagerSession::create([
            'manager_id' => $manager->id,
            'token' => hash_hmac('sha256', $request->session()->getId(), config('app.key')),
            'user_agent' => $request->userAgent(),
            'ip' => $request->ip(),
            'last_active' => now(),
            'expires_at' => now()->addMinutes($ttl),
        ]);

        $this->logAction('login', 'auth', $manager->id, ['login' => $manager->login]);

        $forcePasswordChange = Hash::check('admin123', $manager->password);

        if ($forcePasswordChange) {
            \Log::warning('Manager logged in with default password', [
                'manager_id' => $manager->id,
                'login' => $manager->login,
                'ip' => $request->ip(),
            ]);
        }

        return $this->success(
            [
                'user' => new ManagerResource($manager->load(['managerType', 'avatar'])),
                'force_password_change' => $forcePasswordChange,
            ],
            $forcePasswordChange
                ? 'Авторизация успешна. Необходимо сменить пароль по умолчанию.'
                : 'Авторизация успешна.'
        );
    }

    /**
     * @OA\Post(
     *     path="/auth/logout",
     *     summary="Выход",
     *     tags={"Auth"},
     *     security={{"sanctumAuth":{}}},
     *     @OA\Response(response=200, description="Успешный выход")
     * )
     */
    public function logout(Request $request): JsonResponse
    {
        $manager = Auth::guard('manager')->user();

        $this->logAction('logout', 'auth', $manager?->id, ['login' => $manager?->login]);

        // Удаляем сессию (token хранится хешированным)
        $sessionId = $request->session()->getId();
        ManagerSession::where('token', hash_hmac('sha256', $sessionId, config('app.key')))->delete();

        Auth::guard('manager')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return $this->success(null, 'Вы вышли из системы.');
    }

    /**
     * @OA\Get(
     *     path="/auth/me",
     *     summary="Текущий пользователь",
     *     tags={"Auth"},
     *     security={{"sanctumAuth":{}}},
     *     @OA\Response(response=200, description="Данные менеджера"),
     *     @OA\Response(response=401, description="Не авторизован")
     * )
     */
    public function me(): JsonResponse
    {
        $manager = auth()->user();
        $manager->load(['managerType', 'avatar']);

        return $this->success(new ManagerResource($manager));
    }

    /**
     * @OA\Put(
     *     path="/auth/profile",
     *     summary="Обновить профиль",
     *     tags={"Auth"},
     *     security={{"sanctumAuth":{}}},
     *     @OA\RequestBody(required=true, @OA\JsonContent(
     *         @OA\Property(property="name", type="string"),
     *         @OA\Property(property="email", type="string"),
     *         @OA\Property(property="password", type="string"),
     *         @OA\Property(property="settings", type="object")
     *     )),
     *     @OA\Response(response=200, description="Профиль обновлён")
     * )
     */
    public function updateProfile(Request $request): JsonResponse
    {
        $manager = auth()->user();

        $data = $request->validate([
            'name' => 'sometimes|string|max:255',
            'email' => 'sometimes|nullable|email|max:255',
            'current_password' => 'required_with:password|string',
            'password' => ['sometimes', 'string', Password::min(8)->letters()->mixedCase()->numbers(), 'confirmed'],
            'settings' => 'sometimes|array',
            'avatar_id' => 'sometimes|nullable|integer|exists:files,id',
        ]);

        if (isset($data['password'])) {
            if (!Hash::check($data['current_password'], $manager->password)) {
                return $this->error('Неверный текущий пароль.', 422);
            }
            $data['password'] = Hash::make($data['password']);
            unset($data['current_password']);
        } else {
            unset($data['current_password']);
        }

        if (isset($data['settings'])) {
            $data['settings'] = array_replace_recursive($manager->settings ?? [], $data['settings']);
        }

        $manager->update($data);

        $this->logAction('update', 'auth', $manager->id, ['login' => $manager->login]);

        return $this->success(new ManagerResource($manager->fresh(['managerType', 'avatar'])));
    }
}
