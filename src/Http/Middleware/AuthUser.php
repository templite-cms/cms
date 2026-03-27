<?php

namespace Templite\Cms\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;
use Templite\Cms\Models\UserType;

/**
 * Middleware для аутентификации пользователей сайта по guard'у.
 *
 * Используется модулями для защиты роутов личного кабинета:
 *   Route::middleware('cms.user_auth:author')->group(...)
 *
 * Если guard не передан как параметр middleware, пытается взять из route parameter.
 */
class AuthUser
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next, ?string $guard = null): Response
    {
        // Fallback: берём guard из route parameter если не передан как middleware param
        $guard = $guard ?? $request->route('guard');

        if (!$guard) {
            if ($request->expectsJson()) {
                return response()->json(['message' => 'Guard not specified.'], 400);
            }

            abort(400, 'Guard not specified.');
        }

        // Проверка аутентификации
        if (!Auth::guard($guard)->check()) {
            if ($request->expectsJson()) {
                return response()->json(['message' => 'Unauthenticated.'], 401);
            }

            $userType = UserType::where('guard', $guard)->first();
            $loginUrl = $userType?->getSetting('login_url', "/cabinet/{$guard}/login");

            return redirect($loginUrl);
        }

        $user = Auth::guard($guard)->user();

        // Проверка активности пользователя
        if (!$user->is_active) {
            Auth::guard($guard)->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            if ($request->expectsJson()) {
                return response()->json(['message' => 'Account deactivated.'], 403);
            }

            $userType = UserType::where('guard', $guard)->first();
            $loginUrl = $userType?->getSetting('login_url', "/cabinet/{$guard}/login");

            return redirect($loginUrl)->with('error', 'Аккаунт деактивирован.');
        }

        // Проверяем, что пользователь принадлежит этому guard'у
        if ($user->userType->guard !== $guard) {
            Auth::guard($guard)->logout();

            if ($request->expectsJson()) {
                return response()->json(['message' => 'Unauthorized.'], 403);
            }

            abort(403, 'Unauthorized.');
        }

        // Делаем пользователя доступным через app()
        app()->instance("cms.user.{$guard}", $user);

        return $next($request);
    }
}
