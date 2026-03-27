<?php

use Illuminate\Support\Facades\Route;
use Templite\Cms\Http\Controllers\Admin\DashboardController;
use Templite\Cms\Http\Controllers\Admin\PageController;
use Templite\Cms\Http\Controllers\Admin\PageTypeController;
use Templite\Cms\Http\Controllers\Admin\BlockController;
use Templite\Cms\Http\Controllers\Admin\BlockTypeController;
use Templite\Cms\Http\Controllers\Admin\BlockFieldController;
use Templite\Cms\Http\Controllers\Admin\ActionController;
use Templite\Cms\Http\Controllers\Admin\ComponentController;
use Templite\Cms\Http\Controllers\Admin\TemplatePageController;
use Templite\Cms\Http\Controllers\Admin\GlobalSettingsController;
use Templite\Cms\Http\Controllers\Admin\MediaController;
use Templite\Cms\Http\Controllers\Admin\FileManagerController;
use Templite\Cms\Http\Controllers\Admin\ManagerController;
use Templite\Cms\Http\Controllers\Admin\ManagerTypeController;
use Templite\Cms\Http\Controllers\Admin\ProfileController;
use Templite\Cms\Http\Controllers\Admin\LogController;
use Templite\Cms\Http\Controllers\Admin\LibraryController;
use Templite\Cms\Http\Controllers\Admin\CityController as AdminCityController;
use Templite\Cms\Http\Controllers\Admin\LanguageController as AdminLanguageController;
use Templite\Cms\Http\Controllers\Admin\CoreSettingsController;
use Templite\Cms\Http\Controllers\Admin\PresetController;
use Templite\Cms\Http\Controllers\Admin\ExportImportController as AdminExportImportController;
use Templite\Cms\Http\Controllers\Admin\UserController as AdminUserController;

/*
|--------------------------------------------------------------------------
| CMS Admin Routes (MPA)
|--------------------------------------------------------------------------
|
| Маршруты админки CMS. Каждый контроллер возвращает CmsResponse::page().
| Префикс: /admin/ (настраивается через config/cms.php admin_url)
| Middleware: web, cms.auth
|
*/

// Login (без cms.auth middleware)
Route::prefix(config('cms.admin_url', 'admin'))
    ->middleware(['web'])
    ->group(function () {
        Route::get('/login', function () {
            return \Templite\Cms\Http\CmsResponse::guest(
                'packages/templite/cms/resources/js/entries/login.js',
                ['cmsConfig' => [
                    'admin_url' => '/' . ltrim(\Templite\Cms\Models\CmsConfig::getAdminUrl(), '/'),
                    'two_factor_trust_days' => (int) \Templite\Cms\Models\CmsConfig::getValue('two_factor_trust_days', config('cms.two_factor.trust_days', 0)),
                ]]
            );
        })->name('cms.login');
    });

Route::prefix(config('cms.admin_url', 'admin'))
    ->middleware(['web', 'cms.auth'])
    ->group(function () {

        // -------------------------------------------------------------------
        // Dashboard
        // -------------------------------------------------------------------
        Route::get('/', [DashboardController::class, 'index'])->name('cms.dashboard');

        // -------------------------------------------------------------------
        // Pages (Страницы)
        // -------------------------------------------------------------------
        Route::get('/pages', [PageController::class, 'index'])->name('cms.pages.index');
        Route::get('/pages/create', [PageController::class, 'create'])->name('cms.pages.create');
        Route::get('/pages/{id}/edit', [PageController::class, 'edit'])->name('cms.pages.edit');

        // -------------------------------------------------------------------
        // Page Types (Типы страниц)
        // -------------------------------------------------------------------
        Route::get('/page-types', [PageTypeController::class, 'index'])->name('cms.page-types.index');
        Route::get('/page-types/create', [PageTypeController::class, 'create'])->name('cms.page-types.create');
        Route::get('/page-types/{id}/edit', [PageTypeController::class, 'edit'])->name('cms.page-types.edit');

        // -------------------------------------------------------------------
        // Blocks (Блоки)
        // -------------------------------------------------------------------
        Route::get('/blocks', [BlockController::class, 'index'])->name('cms.blocks.index');
        Route::get('/blocks/create', [BlockController::class, 'create'])->name('cms.blocks.create');
        Route::get('/blocks/{id}/edit', [BlockController::class, 'edit'])->name('cms.blocks.edit');

        // -------------------------------------------------------------------
        // Block Presets (Пресеты блоков)
        // -------------------------------------------------------------------
        Route::get('/presets', [PresetController::class, 'index'])->name('cms.presets.index');
        Route::get('/presets/{id}/edit', [PresetController::class, 'edit'])->name('cms.presets.edit');

        // -------------------------------------------------------------------
        // Block Types (Типы блоков)
        // -------------------------------------------------------------------
        Route::get('/block-types', [BlockTypeController::class, 'index'])->name('cms.block-types.index');
        Route::get('/block-types/create', [BlockTypeController::class, 'create'])->name('cms.block-types.create');
        Route::get('/block-types/{id}/edit', [BlockTypeController::class, 'edit'])->name('cms.block-types.edit');

        // -------------------------------------------------------------------
        // Block Fields (Поля блоков — доп. экран)
        // -------------------------------------------------------------------
        Route::get('/blocks/{blockId}/fields', [BlockFieldController::class, 'index'])->name('cms.block-fields.index');

        // -------------------------------------------------------------------
        // Actions
        // -------------------------------------------------------------------
        Route::get('/actions', [ActionController::class, 'index'])->name('cms.actions.index');
        Route::get('/actions/create', [ActionController::class, 'create'])->name('cms.actions.create');
        Route::get('/actions/{id}/edit', [ActionController::class, 'edit'])->name('cms.actions.edit');

        // -------------------------------------------------------------------
        // Components (Blade-компоненты)
        // -------------------------------------------------------------------
        Route::get('/components', [ComponentController::class, 'index'])->name('cms.components.index');
        Route::get('/components/create', [ComponentController::class, 'create'])->name('cms.components.create');
        Route::get('/components/{id}/edit', [ComponentController::class, 'edit'])->name('cms.components.edit');

        // -------------------------------------------------------------------
        // Templates (Шаблоны)
        // -------------------------------------------------------------------
        Route::get('/templates', [TemplatePageController::class, 'index'])->name('cms.templates.index');
        Route::get('/templates/create', [TemplatePageController::class, 'create'])->name('cms.templates.create');
        Route::get('/templates/{id}/edit', [TemplatePageController::class, 'edit'])->name('cms.templates.edit');

        // -------------------------------------------------------------------
        // Libraries (Библиотеки)
        // -------------------------------------------------------------------
        Route::get('/libraries', [LibraryController::class, 'index'])->name('cms.libraries.index');
        Route::get('/libraries/create', [LibraryController::class, 'create'])->name('cms.libraries.create');
        Route::get('/libraries/{id}/edit', [LibraryController::class, 'edit'])->name('cms.libraries.edit');

        // -------------------------------------------------------------------
        // Media (Медиафайлы)
        // -------------------------------------------------------------------
        Route::get('/media', [MediaController::class, 'index'])->name('cms.media.index');

        // -------------------------------------------------------------------
        // Core Settings (Настройки ядра)
        // -------------------------------------------------------------------
        Route::get('/core-settings', [CoreSettingsController::class, 'index'])->name('cms.core-settings.index');

        // -------------------------------------------------------------------
        // Global Settings (Глобальные настройки)
        // -------------------------------------------------------------------
        Route::get('/settings', [GlobalSettingsController::class, 'index'])->name('cms.settings.index');
        Route::get('/settings-structure', [GlobalSettingsController::class, 'structure'])->name('cms.settings.structure');

        // -------------------------------------------------------------------
        // File Manager (Менеджер публичных файлов)
        // -------------------------------------------------------------------
        Route::get('/file-manager', [FileManagerController::class, 'index'])->name('cms.file-manager.index');

        // -------------------------------------------------------------------
        // Cities (Города)
        // -------------------------------------------------------------------
        Route::get('/cities', [AdminCityController::class, 'index'])->name('cms.cities.index');

        // -------------------------------------------------------------------
        // Languages (Языки)
        // -------------------------------------------------------------------
        Route::get('/languages', [AdminLanguageController::class, 'index'])->name('cms.languages.index');

        // -------------------------------------------------------------------
        // Users (Пользователи сайта)
        // -------------------------------------------------------------------
        Route::get('/users', [AdminUserController::class, 'index'])->name('cms.users.index');

        // -------------------------------------------------------------------
        // Managers (Менеджеры + Типы менеджеров — единая страница)
        // -------------------------------------------------------------------
        Route::get('/managers', [ManagerController::class, 'index'])->name('cms.managers.index');

        // -------------------------------------------------------------------
        // Profile (Профиль текущего менеджера)
        // -------------------------------------------------------------------
        Route::get('/profile', [ProfileController::class, 'index'])->name('cms.profile.index');

        // -------------------------------------------------------------------
        // Export / Import (Импорт / Экспорт)
        // -------------------------------------------------------------------
        Route::get('/export-import', [AdminExportImportController::class, 'index'])
            ->name('cms.export-import.index');

        // -------------------------------------------------------------------
        // Logs (Логи действий)
        // -------------------------------------------------------------------
        Route::get('/logs', [LogController::class, 'index'])->name('cms.logs.index');
    });
