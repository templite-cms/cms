<?php

namespace Templite\Cms\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Templite\Cms\Models\CmsConfig;
use Templite\Cms\Models\Language;

/**
 * Middleware для определения текущего языка.
 *
 * Определяет язык из URL-префикса (/{lang_code}/...).
 * Для дефолтного языка префикс не используется.
 * Для не-дефолтного — вырезает языковой сегмент из URL
 * перед дальнейшей обработкой.
 *
 * Биндинги в контейнере:
 * - current_language  (string|null) — код текущего языка
 * - is_default_language (bool)     — является ли текущий язык дефолтным
 * - default_language  (string)     — код дефолтного языка
 * - languages         (Collection) — коллекция активных Language-моделей
 */
class LocaleResolver
{
    public function handle(Request $request, Closure $next): Response
    {
        // Если мультиязычность отключена — биндим заглушки и выходим
        if (!CmsConfig::getValue('multilang_enabled')) {
            app()->instance('current_language', null);
            app()->instance('is_default_language', true);
            app()->instance('default_language', config('app.locale', 'ru'));
            app()->instance('languages', collect());

            return $next($request);
        }

        $activeCodes = Language::activeCodes();
        $defaultCode = Language::getDefault()?->code ?? config('app.locale', 'ru');

        $segments = $request->segments();
        $firstSegment = $segments[0] ?? null;

        $currentLang = $defaultCode;
        $isDefaultLang = true;

        // Если первый сегмент — код не-дефолтного активного языка,
        // переключаемся на него и вырезаем сегмент из URL
        if ($firstSegment && in_array($firstSegment, $activeCodes, true) && $firstSegment !== $defaultCode) {
            $currentLang = $firstSegment;
            $isDefaultLang = false;

            // Переписываем URL запроса без языкового префикса
            array_shift($segments);
            $newPath = '/' . implode('/', $segments);
            if (empty($segments)) {
                $newPath = '/';
            }

            $server = $request->server->all();
            $server['REQUEST_URI'] = $newPath . ($request->getQueryString() ? '?' . $request->getQueryString() : '');

            $request->initialize(
                $request->query->all(),
                $request->request->all(),
                $request->attributes->all(),
                $request->cookies->all(),
                $request->files->all(),
                $server,
                $request->getContent()
            );

            // Сохраняем реальный путь после удаления языкового префикса,
            // т.к. параметры роута уже захвачены до выполнения middleware
            app()->instance('locale_resolved_path', $newPath);
        }

        app()->setLocale($currentLang);

        app()->instance('current_language', $currentLang);
        app()->instance('is_default_language', $isDefaultLang);
        app()->instance('default_language', $defaultCode);
        app()->instance('languages', Language::getCachedActive());

        return $next($request);
    }
}
