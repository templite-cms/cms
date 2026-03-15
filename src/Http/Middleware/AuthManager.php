<?php

namespace Templite\Cms\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

/**
 * Middleware для аутентификации менеджера (Sanctum) и проверки прав.
 *
 * Аутентификация выполняется до этого middleware:
 * - Inertia (web) роуты: через session (EnsureFrontendRequestsAreStateful)
 * - API роуты: через Bearer token (auth:sanctum guard)
 *
 * auth()->user() работает в обоих случаях: Sanctum резолвит пользователя
 * через session guard (sanctum.guard = ['manager']) или Bearer token.
 *
 * Этот middleware:
 * 1. Проверяет наличие аутентифицированного менеджера
 * 2. Обновляет last_active для session-based запросов
 * 3. Определяет Gates на основе permissions из ManagerType или personal_permissions
 * 4. Проверяет конкретное право (если передано параметром)
 */
class AuthManager
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next, ?string $permission = null): Response
    {
        // Проверка аутентификации (session или Bearer token)
        $manager = auth()->user() ?? auth('manager')->user();

        if (!$manager) {
            if ($request->expectsJson()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Не авторизован.',
                ], 401);
            }

            return redirect()->route('cms.login');
        }

        // Проверка активности аккаунта
        if (!$manager->is_active) {
            if ($request->expectsJson()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Аккаунт деактивирован.',
                ], 403);
            }

            Auth::guard('manager')->logout();

            return redirect()->route('cms.login');
        }

        // Enforce 2FA в required-режиме (только для session-based запросов)
        if ($request->hasSession() && !$request->bearerToken()) {
            $twoFactorMode = \Templite\Cms\Models\CmsConfig::getValue(
                'two_factor_mode',
                config('cms.two_factor.mode', 'off')
            );

            if ($twoFactorMode === 'required' && !$manager->hasTwoFactorEnabled()) {
                $allowed = [
                    'cms.api.auth.logout',
                    'cms.api.auth.me',
                    'cms.api.auth.profile',
                    'cms.api.auth.two-factor.enable',
                    'cms.api.auth.two-factor.confirm',
                ];

                $currentRoute = $request->route()?->getName();
                if ($currentRoute && !in_array($currentRoute, $allowed)) {
                    if ($request->expectsJson()) {
                        return response()->json([
                            'success' => false,
                            'message' => 'Необходимо настроить двухфакторную аутентификацию.',
                            'two_factor_setup_required' => true,
                        ], 403);
                    }

                    return redirect()->route('cms.profile');
                }
            }
        }

        // Обновляем last_active сессии (только для session-based запросов, token хранится хешированным)
        if ($request->hasSession() && $request->session()->getId()) {
            $manager->sessions()
                ->where('token', hash_hmac('sha256', $request->session()->getId(), config('app.key')))
                ->update(['last_active' => now()]);
        }

        // Определяем Gates из permissions (включая wildcard через Gate::before)
        $this->defineGates($manager);
        $this->defineWildcardGate($manager);

        // Проверка конкретного права (если передано)
        if ($permission && !$this->hasPermission($manager, $permission)) {
            if ($request->expectsJson()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Недостаточно прав.',
                ], 403);
            }

            abort(403, 'Недостаточно прав.');
        }

        // Делаем менеджера доступным через app()
        app()->instance('cms.manager', $manager);

        return $next($request);
    }

    /**
     * Определение Laravel Gates на основе permissions менеджера.
     */
    protected function defineGates(mixed $manager): void
    {
        $permissions = $this->getPermissions($manager);

        foreach ($permissions as $permission) {
            Gate::define($permission, function () {
                return true;
            });
        }
    }

    /**
     * Получить список прав менеджера.
     * Если use_personal_permissions — берём personal_permissions.
     * Иначе — permissions из manager_type.
     */
    protected function getPermissions(mixed $manager): array
    {
        if ($manager->use_personal_permissions && !empty($manager->personal_permissions)) {
            return $manager->personal_permissions;
        }

        $manager->loadMissing('managerType');

        return $manager->managerType?->permissions ?? [];
    }

    /**
     * Wildcard Gate: если у менеджера есть '*' или 'group.*', разрешить доступ.
     * Нужно для корректной работы middleware can:permission с wildcard-правами.
     */
    protected function defineWildcardGate(mixed $manager): void
    {
        $permissions = $this->getPermissions($manager);

        Gate::before(function ($user, $ability) use ($permissions) {
            if (in_array('*', $permissions)) {
                return true;
            }

            $group = explode('.', $ability)[0] ?? '';
            if (in_array($group . '.*', $permissions)) {
                return true;
            }

            return null; // продолжить обычную проверку Gates
        });
    }

    /**
     * Проверить конкретное право.
     */
    protected function hasPermission(mixed $manager, string $permission): bool
    {
        $permissions = $this->getPermissions($manager);

        // Wildcard: если есть '*', доступ ко всему
        if (in_array('*', $permissions)) {
            return true;
        }

        // Группы: pages.* даёт доступ к pages.view, pages.create и т.д.
        $group = explode('.', $permission)[0] ?? '';
        if (in_array($group . '.*', $permissions)) {
            return true;
        }

        return in_array($permission, $permissions);
    }
}
