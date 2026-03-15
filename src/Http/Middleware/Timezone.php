<?php

namespace Templite\Cms\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Middleware для установки таймзоны из глобальных настроек CMS.
 *
 * Считывает timezone из глобальных полей или конфига CMS
 * и устанавливает как дефолтную PHP-таймзону и Carbon-таймзону.
 */
class Timezone
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $timezone = $this->resolveTimezone();

        if ($timezone && $this->isValidTimezone($timezone)) {
            date_default_timezone_set($timezone);
            config(['app.timezone' => $timezone]);
        }

        return $next($request);
    }

    /**
     * Определить таймзону.
     * Приоритет: глобальные поля > конфиг CMS > конфиг app.
     */
    protected function resolveTimezone(): ?string
    {
        // 1. Из глобальных полей (если загружены middleware GlobalFieldsMiddleware)
        if (app()->bound('global_fields')) {
            $global = app('global_fields');
            if (!empty($global['timezone'])) {
                return $global['timezone'];
            }
        }

        // 2. Из настроек CMS (БД)
        $dbTimezone = \Templite\Cms\Models\CmsConfig::getValue('timezone');
        if ($dbTimezone) {
            return $dbTimezone;
        }

        // 3. Из конфига CMS (файл)
        $cmsTimezone = config('cms.timezone');
        if ($cmsTimezone) {
            return $cmsTimezone;
        }

        // 3. Дефолт из app.timezone (не меняем)
        return null;
    }

    /**
     * Проверить валидность таймзоны.
     */
    protected function isValidTimezone(string $timezone): bool
    {
        return in_array($timezone, timezone_identifiers_list(), true);
    }
}
