<?php

use Illuminate\Support\Facades\Route;
use Templite\Cms\Http\Controllers\Web\RenderController;
use Templite\Cms\Http\Controllers\Web\SitemapController;
use Templite\Cms\Http\Controllers\Web\BlockActionController;

/*
|--------------------------------------------------------------------------
| CMS Public Routes
|--------------------------------------------------------------------------
|
| Маршруты публичной части сайта.
| Рендеринг страниц, sitemap, actions блоков.
| Middleware: web, cms.locale, cms.city_resolver, cms.global_fields, cms.timezone
|
*/

Route::middleware(['web', 'cms.security_headers', 'cms.locale', 'cms.city_resolver', 'cms.global_fields', 'cms.timezone'])->group(function () {

    // XML Sitemap
    Route::get('/sitemap.xml', [SitemapController::class, 'index'])->name('cms.sitemap');
    Route::get('/sitemap-global.xml', [SitemapController::class, 'global'])->name('cms.sitemap.global');
    Route::get('/sitemap-{slug}.xml', [SitemapController::class, 'city'])
        ->where('slug', '[a-z0-9-]+')
        ->name('cms.sitemap.city');

    // Block Actions (обработка POST-запросов форм)
    // TASK-S01: Rate limiting (10 запросов/мин) + honeypot anti-bot защита.
    // CSRF-токен проверяется автоматически через middleware 'web' (VerifyCsrfToken).
    Route::post('/cms/block-action/{pageBlockId}', [BlockActionController::class, 'handle'])
        ->middleware(['throttle:10,1', 'cms.honeypot'])
        ->name('cms.block-action');
    Route::post('/cms/action/{blockSlug}', [BlockActionController::class, 'handleBySlug'])
        ->middleware(['throttle:10,1', 'cms.honeypot'])
        ->name('cms.action');

    // Главная страница
    Route::get('/', [RenderController::class, 'home'])->name('cms.home');

    // Catch-all: рендер страниц по URL (должен быть последним!)
    // Исключаем префикс админки и API из catch-all
    $adminUrl = config('cms.admin_url', 'cms');
    Route::get('/{url}', [RenderController::class, 'page'])
        ->where('url', '^(?!' . preg_quote($adminUrl, '/') . '(/|$)|api/|\.well-known/|oauth/).*')
        ->name('cms.page');
});
