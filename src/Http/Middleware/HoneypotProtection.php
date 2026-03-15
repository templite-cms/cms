<?php

namespace Templite\Cms\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * TASK-S01: Anti-bot защита через honeypot-поле.
 *
 * Принцип работы:
 * - В форму добавляется скрытое поле (по умолчанию `_hp_name`), невидимое для пользователя.
 * - Боты, заполняющие все поля автоматически, попадают в ловушку.
 * - Если honeypot-поле заполнено — запрос отклоняется.
 * - Также проверяется `_hp_time` (timestamp создания формы) — слишком быстрая отправка
 *   (менее 2 секунд) является признаком бота.
 *
 * Для использования в Blade-шаблонах добавьте:
 *   <x-cms::honeypot />
 *
 * Middleware можно отключить через конфигурацию:
 *   config('cms.honeypot.enabled', true)
 */
class HoneypotProtection
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Проверяем только POST-запросы
        if (!$request->isMethod('POST')) {
            return $next($request);
        }

        // Если honeypot отключён в конфигурации — пропускаем
        if (!config('cms.honeypot.enabled', true)) {
            return $next($request);
        }

        $fieldName = config('cms.honeypot.field', '_hp_name');
        $timeField = config('cms.honeypot.time_field', '_hp_time');
        $minTime = config('cms.honeypot.min_time', 2); // минимум 2 секунды на заполнение формы

        // Проверка honeypot-поля: если заполнено — это бот
        if ($request->filled($fieldName)) {
            \Log::warning('Honeypot triggered: поле заполнено', [
                'ip' => $request->ip(),
                'url' => $request->fullUrl(),
                'user_agent' => $request->userAgent(),
            ]);

            // Возвращаем 200 с success, чтобы бот не понял, что обнаружен
            return response()->json([
                'success' => true,
                'data' => [],
            ]);
        }

        // Проверка времени заполнения формы
        if ($request->has($timeField)) {
            $formCreatedAt = (int) $request->input($timeField);
            $elapsed = time() - $formCreatedAt;

            if ($elapsed < $minTime) {
                \Log::warning('Honeypot triggered: слишком быстрая отправка', [
                    'ip' => $request->ip(),
                    'url' => $request->fullUrl(),
                    'elapsed_seconds' => $elapsed,
                    'user_agent' => $request->userAgent(),
                ]);

                return response()->json([
                    'success' => true,
                    'data' => [],
                ]);
            }
        }

        // Удаляем honeypot-поля из запроса, чтобы они не попали в обработку
        $request->request->remove($fieldName);
        $request->request->remove($timeField);

        return $next($request);
    }
}
