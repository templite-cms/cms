<?php

namespace Templite\Cms\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Middleware для добавления заголовков безопасности к публичным ответам.
 *
 * Добавляет Content-Security-Policy, X-Content-Type-Options,
 * X-Frame-Options и Referrer-Policy.
 *
 * Все директивы CSP конфигурируются через config('cms.security_headers').
 *
 * @see https://developer.mozilla.org/en-US/docs/Web/HTTP/CSP
 * @see SECURITY_REMEDIATION_TASKS.md — TASK-S13, уязвимость M-04
 */
class AddSecurityHeaders
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        /** @var Response $response */
        $response = $next($request);

        $config = config('cms.security_headers', []);

        if (empty($config['enabled'])) {
            return $response;
        }

        // Content-Security-Policy
        $csp = $this->buildCsp($config['csp'] ?? []);
        if ($csp !== '') {
            $response->headers->set('Content-Security-Policy', $csp);
        }

        // X-Content-Type-Options
        $response->headers->set(
            'X-Content-Type-Options',
            $config['x_content_type_options'] ?? 'nosniff'
        );

        // X-Frame-Options
        $response->headers->set(
            'X-Frame-Options',
            $config['x_frame_options'] ?? 'DENY'
        );

        // Referrer-Policy
        $response->headers->set(
            'Referrer-Policy',
            $config['referrer_policy'] ?? 'strict-origin-when-cross-origin'
        );

        // Permissions-Policy (опционально)
        $permissionsPolicy = $config['permissions_policy'] ?? null;
        if ($permissionsPolicy !== null && $permissionsPolicy !== '') {
            $response->headers->set('Permissions-Policy', $permissionsPolicy);
        }

        return $response;
    }

    /**
     * Собрать строку Content-Security-Policy из массива директив.
     *
     * @param array<string, string> $directives Ключ — название директивы, значение — правила.
     */
    protected function buildCsp(array $directives): string
    {
        if (empty($directives)) {
            return '';
        }

        $parts = [];

        foreach ($directives as $directive => $value) {
            if ($value === '' || $value === null) {
                continue;
            }

            $parts[] = $directive . ' ' . $value;
        }

        return implode('; ', $parts);
    }
}
