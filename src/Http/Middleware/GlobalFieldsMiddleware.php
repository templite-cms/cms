<?php

namespace Templite\Cms\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Templite\Cms\Services\CacheManager;
use Templite\Cms\Services\GlobalFieldsResolver;

/**
 * Middleware для загрузки глобальных полей.
 *
 * Загружает все глобальные поля (настройки сайта) и делает
 * их доступными через app('global_fields').
 * Используется на публичных маршрутах для рендеринга страниц.
 */
class GlobalFieldsMiddleware
{
    public function __construct(
        protected CacheManager $cacheManager,
        protected GlobalFieldsResolver $resolver,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $globalFields = $this->cacheManager->getGlobalFields();

        if ($globalFields === null) {
            $globalFields = $this->resolver->resolve();
            $this->cacheManager->putGlobalFields($globalFields);
        }

        app()->instance('global_fields', $globalFields);
        view()->share('global', $globalFields);

        return $next($request);
    }
}
