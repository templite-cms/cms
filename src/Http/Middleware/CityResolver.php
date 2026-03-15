<?php

namespace Templite\Cms\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Templite\Cms\Models\City;
use Templite\Cms\Models\CmsConfig;

/**
 * Middleware для определения текущего города.
 *
 * Определяет город из URL-префикса (/{city_slug}/...) или cookie.
 * Сохраняет текущий город в app('current_city').
 * Пропускает при отключённой фиче мультигорода.
 */
class CityResolver
{
    public function handle(Request $request, Closure $next): Response
    {
        // Пропускаем если мультигород отключён
        if (!CmsConfig::getValue('multicity_enabled', false)) {
            return $next($request);
        }

        $city = null;
        $strippedUrl = null;

        // Извлекаем первый сегмент URL
        $path = trim($request->path(), '/');
        $segments = $path ? explode('/', $path, 2) : [];
        $firstSegment = $segments[0] ?? '';

        // Проверяем, является ли первый сегмент slug-ом города
        if ($firstSegment) {
            $city = City::findBySlug($firstSegment);
            if ($city) {
                $strippedUrl = isset($segments[1]) ? '/' . $segments[1] : '/';
            }
        }

        // Если город не найден в URL — берём из cookie или дефолтный
        if (!$city) {
            $cookieSlug = $request->cookie('city_slug');
            if ($cookieSlug) {
                $city = City::findBySlug($cookieSlug);
            }
            if (!$city) {
                $city = City::getDefault();
            }
        }

        // Регистрируем город и stripped URL в контейнере
        if ($city) {
            app()->instance('current_city', $city);
        }

        if ($strippedUrl !== null) {
            app()->instance('city_stripped_url', $strippedUrl);
            app()->instance('city_from_url', true);
        } else {
            app()->instance('city_from_url', false);
        }

        // Расшариваем город во View
        if ($city) {
            view()->share('city', $city);
        }

        $response = $next($request);

        // Устанавливаем cookie с текущим городом
        if ($city) {
            $response->headers->setCookie(
                cookie('city_slug', $city->slug, 60 * 24 * 365, '/')
            );
        }

        return $response;
    }
}
