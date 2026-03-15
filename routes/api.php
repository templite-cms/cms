<?php

use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful;
use Templite\Cms\Http\Controllers\Api\AuthController;
use Templite\Cms\Http\Controllers\Api\ApiTokenController;
use Templite\Cms\Http\Controllers\Api\PageController;
use Templite\Cms\Http\Controllers\Api\PageTypeController;
use Templite\Cms\Http\Controllers\Api\PageTypeAttributeController;
use Templite\Cms\Http\Controllers\Api\PageAttributeValueController;
use Templite\Cms\Http\Controllers\Api\PageBlockController;
use Templite\Cms\Http\Controllers\Api\BlockController;
use Templite\Cms\Http\Controllers\Api\BlockTypeController;
use Templite\Cms\Http\Controllers\Api\BlockFieldController;
use Templite\Cms\Http\Controllers\Api\BlockTabSectionController;
use Templite\Cms\Http\Controllers\Api\BlockCodeController;
use Templite\Cms\Http\Controllers\Api\ActionController;
use Templite\Cms\Http\Controllers\Api\BlockActionController;
use Templite\Cms\Http\Controllers\Api\ComponentController;
use Templite\Cms\Http\Controllers\Api\ComponentCodeController;
use Templite\Cms\Http\Controllers\Api\TemplateController;
use Templite\Cms\Http\Controllers\Api\TemplateCodeController;
use Templite\Cms\Http\Controllers\Api\TemplateFieldController;
use Templite\Cms\Http\Controllers\Api\TemplateTabSectionController;
use Templite\Cms\Http\Controllers\Api\GlobalSettingsController;
use Templite\Cms\Http\Controllers\Api\MediaController;
use Templite\Cms\Http\Controllers\Api\ManagerController;
use Templite\Cms\Http\Controllers\Api\ManagerTypeController;
use Templite\Cms\Http\Controllers\Api\LogController;
use Templite\Cms\Http\Controllers\Api\FileManagerController;
use Templite\Cms\Http\Controllers\Api\CoreSettingsController;
use Templite\Cms\Http\Controllers\Api\LibraryController;
use Templite\Cms\Http\Controllers\Api\CacheAssetController;
use Templite\Cms\Http\Controllers\Api\BlockPresetController;
use Templite\Cms\Http\Controllers\Api\CityController;
use Templite\Cms\Http\Controllers\Api\CityPageController;
use Templite\Cms\Http\Controllers\Api\LanguageController;
use Templite\Cms\Http\Controllers\Api\TranslationController;
use Templite\Cms\Http\Controllers\Api\ExportImportController;
use Templite\Cms\Http\Controllers\Api\TwoFactorController;
use Templite\Cms\Http\Controllers\Api\QueueController;
use Templite\Cms\Http\Controllers\Api\ScheduleController;

/*
|--------------------------------------------------------------------------
| CMS API Routes
|--------------------------------------------------------------------------
|
| Все REST API маршруты CMS.
| Префикс: /api/cms/
| Middleware: EnsureFrontendRequestsAreStateful (Sanctum)
| Аутентификация: auth:sanctum
|
*/

Route::prefix('api/cms')
    ->middleware([EnsureFrontendRequestsAreStateful::class])
    ->group(function () {

        // ---------------------------------------------------------------
        // Auth (без авторизации)
        // ---------------------------------------------------------------
        Route::prefix('auth')->group(function () {
            Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:5,1')->name('cms.api.auth.login');
            Route::post('/two-factor/verify', [TwoFactorController::class, 'verify'])
                ->middleware('throttle:5,1')
                ->name('cms.api.auth.two-factor.verify');
        });

        // ---------------------------------------------------------------
        // Default Avatars (без авторизации — статические ресурсы)
        // ---------------------------------------------------------------
        Route::get('avatars/{number}', function (int $number) {
            $number = max(1, min(30, $number));
            $filename = 'avatar_' . str_pad($number, 2, '0', STR_PAD_LEFT) . '.png';
            $path = __DIR__ . '/../resources/img/avatars/' . $filename;

            if (!file_exists($path)) {
                abort(404);
            }

            return response()->file($path, [
                'Content-Type' => 'image/png',
                'Cache-Control' => 'public, max-age=31536000, immutable',
            ]);
        })->where('number', '[0-9]+')->name('cms.api.avatars');

        // ---------------------------------------------------------------
        // Защищённые маршруты (Sanctum + CMS Auth)
        // ---------------------------------------------------------------
        Route::middleware(['auth:sanctum', 'cms.auth', 'throttle:cms-api'])->group(function () {

            // -----------------------------------------------------------
            // Auth (с авторизацией)
            // -----------------------------------------------------------
            Route::prefix('auth')->group(function () {
                Route::post('/logout', [AuthController::class, 'logout'])->name('cms.api.auth.logout');
                Route::get('/me', [AuthController::class, 'me'])->name('cms.api.auth.me');
                Route::put('/profile', [AuthController::class, 'updateProfile'])->name('cms.api.auth.profile');

                // Two-Factor Authentication
                Route::post('/two-factor/enable', [TwoFactorController::class, 'enable'])->name('cms.api.auth.two-factor.enable');
                Route::post('/two-factor/confirm', [TwoFactorController::class, 'confirm'])->name('cms.api.auth.two-factor.confirm');
                Route::delete('/two-factor/disable', [TwoFactorController::class, 'disable'])->name('cms.api.auth.two-factor.disable');
                Route::get('/two-factor/recovery-codes', [TwoFactorController::class, 'recoveryCodes'])->name('cms.api.auth.two-factor.recovery-codes');
                Route::post('/two-factor/recovery-codes', [TwoFactorController::class, 'regenerateRecoveryCodes'])->name('cms.api.auth.two-factor.recovery-codes.regenerate');
            });

            // -----------------------------------------------------------
            // API Tokens
            // -----------------------------------------------------------
            Route::get('/tokens', [ApiTokenController::class, 'index'])->name('cms.api.tokens.index');
            Route::post('/tokens', [ApiTokenController::class, 'store'])->name('cms.api.tokens.store');
            Route::delete('/tokens/{id}', [ApiTokenController::class, 'destroy'])->name('cms.api.tokens.destroy');

            // -----------------------------------------------------------
            // Pages (Страницы)
            // -----------------------------------------------------------
            Route::get('/pages', [PageController::class, 'index'])->name('cms.api.pages.index');
            Route::get('/pages/tree', [PageController::class, 'tree'])->name('cms.api.pages.tree');
            Route::post('/pages', [PageController::class, 'store'])->name('cms.api.pages.store');
            Route::get('/pages/{id}', [PageController::class, 'show'])->name('cms.api.pages.show');
            Route::put('/pages/{id}', [PageController::class, 'update'])->name('cms.api.pages.update');
            Route::delete('/pages/{id}', [PageController::class, 'destroy'])->name('cms.api.pages.destroy');
            Route::post('/pages/{id}/copy', [PageController::class, 'copy'])->name('cms.api.pages.copy');
            Route::put('/pages/reorder', [PageController::class, 'reorder'])->name('cms.api.pages.reorder');
            Route::get('/pages/{id}/preview', [PageController::class, 'preview'])->middleware('cms.global_fields')->name('cms.api.pages.preview');
            Route::post('/pages/{id}/screenshot', [PageController::class, 'screenshot'])->name('cms.api.pages.screenshot');

            // -----------------------------------------------------------
            // Page Types (Типы страниц)
            // -----------------------------------------------------------
            Route::get('/page-types', [PageTypeController::class, 'index'])->name('cms.api.page-types.index');
            Route::post('/page-types', [PageTypeController::class, 'store'])->name('cms.api.page-types.store');
            Route::get('/page-types/{id}', [PageTypeController::class, 'show'])->name('cms.api.page-types.show');
            Route::put('/page-types/{id}', [PageTypeController::class, 'update'])->name('cms.api.page-types.update');
            Route::delete('/page-types/{id}', [PageTypeController::class, 'destroy'])->name('cms.api.page-types.destroy');

            // -----------------------------------------------------------
            // Page Type Attributes (Атрибуты типов страниц)
            // -----------------------------------------------------------
            Route::get('/page-types/{typeId}/attributes', [PageTypeAttributeController::class, 'index'])->name('cms.api.page-type-attributes.index');
            Route::post('/page-types/{typeId}/attributes', [PageTypeAttributeController::class, 'store'])->name('cms.api.page-type-attributes.store');
            Route::put('/page-type-attributes/{id}', [PageTypeAttributeController::class, 'update'])->name('cms.api.page-type-attributes.update');
            Route::delete('/page-type-attributes/{id}', [PageTypeAttributeController::class, 'destroy'])->name('cms.api.page-type-attributes.destroy');
            Route::put('/page-types/{typeId}/attributes/reorder', [PageTypeAttributeController::class, 'reorder'])->name('cms.api.page-type-attributes.reorder');

            // -----------------------------------------------------------
            // Page Attribute Values (Значения атрибутов страниц)
            // -----------------------------------------------------------
            Route::get('/pages/{pageId}/attributes', [PageAttributeValueController::class, 'index'])->name('cms.api.page-attribute-values.index');
            Route::put('/pages/{pageId}/attributes', [PageAttributeValueController::class, 'update'])->name('cms.api.page-attribute-values.update');

            // -----------------------------------------------------------
            // Page Blocks (Блоки на страницах)
            // -----------------------------------------------------------
            Route::get('/pages/{pageId}/blocks', [PageBlockController::class, 'index'])->name('cms.api.page-blocks.index');
            Route::post('/pages/{pageId}/blocks', [PageBlockController::class, 'store'])->name('cms.api.page-blocks.store');
            Route::get('/page-blocks/{id}', [PageBlockController::class, 'show'])->name('cms.api.page-blocks.show');
            Route::put('/page-blocks/{id}', [PageBlockController::class, 'update'])->name('cms.api.page-blocks.update');
            Route::delete('/page-blocks/{id}', [PageBlockController::class, 'destroy'])->name('cms.api.page-blocks.destroy');
            Route::put('/pages/{pageId}/blocks/reorder', [PageBlockController::class, 'reorder'])->name('cms.api.page-blocks.reorder');
            Route::post('/page-blocks/{id}/copy', [PageBlockController::class, 'copy'])->name('cms.api.page-blocks.copy');
            Route::put('/page-blocks/{id}/toggle-cache', [PageBlockController::class, 'toggleCache'])->name('cms.api.page-blocks.toggle-cache');
            Route::post('/page-blocks/{id}/invalidate-cache', [PageBlockController::class, 'invalidateCache'])->name('cms.api.page-blocks.invalidate-cache');
            Route::match(['get', 'post'], '/page-blocks/{id}/preview', [PageBlockController::class, 'preview'])->middleware('cms.global_fields')->name('cms.api.page-blocks.preview');
            Route::get('/page-blocks/{id}/versions', [PageBlockController::class, 'versions'])->name('cms.api.page-blocks.versions');
            Route::get('/page-blocks/{id}/versions/{versionId}', [PageBlockController::class, 'showVersion'])->name('cms.api.page-blocks.versions.show');
            Route::put('/page-blocks/{id}/version/{versionId}', [PageBlockController::class, 'setActiveVersion'])->name('cms.api.page-blocks.version.set');

            // -----------------------------------------------------------
            // Blocks (Блоки)
            // -----------------------------------------------------------
            Route::get('/blocks', [BlockController::class, 'index'])->name('cms.api.blocks.index');
            Route::post('/blocks', [BlockController::class, 'store'])->name('cms.api.blocks.store');
            Route::get('/blocks/{id}', [BlockController::class, 'show'])->name('cms.api.blocks.show');
            Route::put('/blocks/{id}', [BlockController::class, 'update'])->name('cms.api.blocks.update');
            Route::delete('/blocks/{id}', [BlockController::class, 'destroy'])->name('cms.api.blocks.destroy');
            Route::post('/blocks/{id}/copy', [BlockController::class, 'copy'])->name('cms.api.blocks.copy');
            Route::match(['get', 'post'], '/blocks/{id}/preview', [BlockController::class, 'preview'])->middleware('cms.global_fields')->name('cms.api.blocks.preview');
            Route::post('/blocks/{id}/screenshot', [BlockController::class, 'screenshot'])->name('cms.api.blocks.screenshot');

            // -----------------------------------------------------------
            // Block Presets (Пресеты блоков)
            // -----------------------------------------------------------
            Route::get('/block-presets', [BlockPresetController::class, 'index'])->name('cms.api.block-presets.index');
            Route::post('/block-presets', [BlockPresetController::class, 'store'])->name('cms.api.block-presets.store');
            Route::get('/block-presets/{id}', [BlockPresetController::class, 'show'])->name('cms.api.block-presets.show');
            Route::put('/block-presets/{id}', [BlockPresetController::class, 'update'])->name('cms.api.block-presets.update');
            Route::delete('/block-presets/{id}', [BlockPresetController::class, 'destroy'])->name('cms.api.block-presets.destroy');
            Route::post('/block-presets/{id}/preview', [BlockPresetController::class, 'preview'])->middleware('cms.global_fields')->name('cms.api.block-presets.preview');
            Route::get('/blocks/{blockId}/presets', [BlockPresetController::class, 'forBlock'])->name('cms.api.blocks.presets');

            // -----------------------------------------------------------
            // Block Types (Типы блоков)
            // -----------------------------------------------------------
            Route::get('/block-types', [BlockTypeController::class, 'index'])->name('cms.api.block-types.index');
            Route::post('/block-types', [BlockTypeController::class, 'store'])->name('cms.api.block-types.store');
            Route::put('/block-types/{id}', [BlockTypeController::class, 'update'])->name('cms.api.block-types.update');
            Route::delete('/block-types/{id}', [BlockTypeController::class, 'destroy'])->name('cms.api.block-types.destroy');

            // -----------------------------------------------------------
            // Block Fields (Поля блоков)
            // -----------------------------------------------------------
            Route::get('/blocks/{blockId}/fields', [BlockFieldController::class, 'index'])->name('cms.api.block-fields.index');
            Route::post('/blocks/{blockId}/fields', [BlockFieldController::class, 'store'])->name('cms.api.block-fields.store');
            Route::put('/block-fields/{id}', [BlockFieldController::class, 'update'])->name('cms.api.block-fields.update');
            Route::delete('/block-fields/{id}', [BlockFieldController::class, 'destroy'])->name('cms.api.block-fields.destroy');
            Route::put('/blocks/{blockId}/fields/reorder', [BlockFieldController::class, 'reorder'])->name('cms.api.block-fields.reorder');

            // -----------------------------------------------------------
            // Block Tabs & Sections (Вкладки и секции блоков)
            // -----------------------------------------------------------
            Route::post('/blocks/{blockId}/tabs', [BlockTabSectionController::class, 'storeTab'])->name('cms.api.block-tabs.store');
            Route::put('/block-tabs/{id}', [BlockTabSectionController::class, 'updateTab'])->name('cms.api.block-tabs.update');
            Route::delete('/block-tabs/{id}', [BlockTabSectionController::class, 'destroyTab'])->name('cms.api.block-tabs.destroy');
            Route::put('/blocks/{blockId}/tabs/reorder', [BlockTabSectionController::class, 'reorderTabs'])->name('cms.api.block-tabs.reorder');
            Route::post('/blocks/{blockId}/sections', [BlockTabSectionController::class, 'storeSection'])->name('cms.api.block-sections.store');
            Route::put('/block-sections/{id}', [BlockTabSectionController::class, 'updateSection'])->name('cms.api.block-sections.update');
            Route::delete('/block-sections/{id}', [BlockTabSectionController::class, 'destroySection'])->name('cms.api.block-sections.destroy');
            Route::put('/blocks/{blockId}/sections/reorder', [BlockTabSectionController::class, 'reorderSections'])->name('cms.api.block-sections.reorder');

            // -----------------------------------------------------------
            // Block Code (Код блоков: template, style, script)
            // -----------------------------------------------------------
            Route::get('/blocks/{id}/code', [BlockCodeController::class, 'show'])->name('cms.api.block-code.show');
            Route::put('/blocks/{id}/code', [BlockCodeController::class, 'update'])->middleware(['can:blocks.code', 'throttle:10,1'])->name('cms.api.block-code.update');

            // -----------------------------------------------------------
            // Actions (управление Actions требует permission)
            // -----------------------------------------------------------
            Route::get('/actions', [ActionController::class, 'index'])->name('cms.api.actions.index');
            Route::get('/actions/{id}', [ActionController::class, 'show'])->name('cms.api.actions.show');
            Route::middleware(['can:actions.code', 'throttle:10,1'])->group(function () {
                Route::post('/actions', [ActionController::class, 'store'])->name('cms.api.actions.store');
                Route::put('/actions/{id}', [ActionController::class, 'update'])->name('cms.api.actions.update');
                Route::delete('/actions/{id}', [ActionController::class, 'destroy'])->name('cms.api.actions.destroy');
                Route::post('/actions/{id}/test', [ActionController::class, 'test'])->name('cms.api.actions.test');
            });

            // -----------------------------------------------------------
            // Block Actions (Привязка Actions к блокам)
            // -----------------------------------------------------------
            Route::get('/blocks/{blockId}/actions', [BlockActionController::class, 'index'])->name('cms.api.block-actions.index');
            Route::post('/blocks/{blockId}/actions', [BlockActionController::class, 'store'])->name('cms.api.block-actions.store');
            Route::put('/block-actions/{id}', [BlockActionController::class, 'update'])->name('cms.api.block-actions.update');
            Route::delete('/block-actions/{id}', [BlockActionController::class, 'destroy'])->name('cms.api.block-actions.destroy');

            // -----------------------------------------------------------
            // Components (Blade-компоненты)
            // -----------------------------------------------------------
            Route::get('/components', [ComponentController::class, 'index'])->name('cms.api.components.index');
            Route::post('/components', [ComponentController::class, 'store'])->name('cms.api.components.store');
            Route::get('/components/{id}', [ComponentController::class, 'show'])->name('cms.api.components.show');
            Route::put('/components/{id}', [ComponentController::class, 'update'])->name('cms.api.components.update');
            Route::delete('/components/{id}', [ComponentController::class, 'destroy'])->name('cms.api.components.destroy');
            Route::get('/components/{id}/preview', [ComponentController::class, 'preview'])->middleware('cms.global_fields')->name('cms.api.components.preview');

            // -----------------------------------------------------------
            // Component Code (Код компонентов: template, style, script)
            // -----------------------------------------------------------
            Route::get('/components/{id}/code', [ComponentCodeController::class, 'show'])->name('cms.api.component-code.show');
            Route::put('/components/{id}/code', [ComponentCodeController::class, 'update'])->middleware(['can:components.code', 'throttle:10,1'])->name('cms.api.component-code.update');

            // -----------------------------------------------------------
            // Templates (Шаблоны страниц)
            // -----------------------------------------------------------
            Route::get('/templates', [TemplateController::class, 'index'])->name('cms.api.templates.index');
            Route::post('/templates', [TemplateController::class, 'store'])->name('cms.api.templates.store');
            Route::get('/templates/{id}', [TemplateController::class, 'show'])->name('cms.api.templates.show');
            Route::put('/templates/{id}', [TemplateController::class, 'update'])->name('cms.api.templates.update');
            Route::delete('/templates/{id}', [TemplateController::class, 'destroy'])->name('cms.api.templates.destroy');

            // -----------------------------------------------------------
            // Template Code (Blade/CSS/JS шаблонов)
            // -----------------------------------------------------------
            Route::get('/templates/{id}/code', [TemplateCodeController::class, 'show'])->name('cms.api.template-code.show');
            Route::put('/templates/{id}/code', [TemplateCodeController::class, 'update'])->middleware(['can:templates.code', 'throttle:10,1'])->name('cms.api.template-code.update');
            Route::get('/templates/{id}/preview', [TemplateCodeController::class, 'preview'])->middleware('cms.global_fields')->name('cms.api.template-code.preview');

            // -----------------------------------------------------------
            // Template Fields (Поля шаблонов)
            // -----------------------------------------------------------
            Route::get('/templates/{templateId}/fields', [TemplateFieldController::class, 'index'])->name('cms.api.template-fields.index');
            Route::post('/templates/{templateId}/fields', [TemplateFieldController::class, 'store'])->name('cms.api.template-fields.store');
            Route::put('/templates/{templateId}/fields/reorder', [TemplateFieldController::class, 'reorder'])->name('cms.api.template-fields.reorder');

            // -----------------------------------------------------------
            // Template Tabs & Sections (Вкладки и секции шаблонов)
            // -----------------------------------------------------------
            Route::post('/templates/{templateId}/tabs', [TemplateTabSectionController::class, 'storeTab'])->name('cms.api.template-tabs.store');
            Route::put('/templates/{templateId}/tabs/reorder', [TemplateTabSectionController::class, 'reorderTabs'])->name('cms.api.template-tabs.reorder');
            Route::post('/templates/{templateId}/sections', [TemplateTabSectionController::class, 'storeSection'])->name('cms.api.template-sections.store');
            Route::put('/templates/{templateId}/sections/reorder', [TemplateTabSectionController::class, 'reorderSections'])->name('cms.api.template-sections.reorder');

            // -----------------------------------------------------------
            // Core Settings — MCP (перед /core-settings/{id} чтобы не было конфликта)
            // -----------------------------------------------------------
            Route::get('/core-settings/mcp/info', [CoreSettingsController::class, 'mcpInfo'])->name('cms.api.core-settings.mcp.info');
            Route::middleware('can:settings.view')->group(function () {
                Route::get('/core-settings/mcp', [CoreSettingsController::class, 'mcpSettings'])->name('cms.api.core-settings.mcp');
            });
            Route::middleware('can:mcp.tokens')->group(function () {
                Route::put('/core-settings/mcp', [CoreSettingsController::class, 'updateMcpSettings'])->name('cms.api.core-settings.mcp.update');
                Route::post('/core-settings/mcp/generate-token', [CoreSettingsController::class, 'generateMcpToken'])->name('cms.api.core-settings.mcp.generate-token');
                Route::delete('/core-settings/mcp/revoke-token', [CoreSettingsController::class, 'revokeMcpToken'])->name('cms.api.core-settings.mcp.revoke-token');
            });

            // -----------------------------------------------------------
            // Core Settings (Настройки ядра)
            // -----------------------------------------------------------
            Route::get('/core-settings', [CoreSettingsController::class, 'index'])->middleware('can:settings.view')->name('cms.api.core-settings.index');
            Route::put('/core-settings', [CoreSettingsController::class, 'update'])->middleware('can:settings.edit')->name('cms.api.core-settings.update');

            // -----------------------------------------------------------
            // Queue monitoring — read
            // -----------------------------------------------------------
            Route::prefix('core-settings/queues')->middleware('can:settings.view')->group(function () {
                Route::get('/stats', [QueueController::class, 'stats'])->name('cms.api.queues.stats');
                Route::get('/failed', [QueueController::class, 'failed'])->name('cms.api.queues.failed');
            });

            // -----------------------------------------------------------
            // Queue monitoring — actions
            // -----------------------------------------------------------
            Route::prefix('core-settings/queues')->middleware(['can:settings.edit', 'throttle:10,1'])->group(function () {
                Route::post('/restart', [QueueController::class, 'restart'])->name('cms.api.queues.restart');
                Route::post('/pause', [QueueController::class, 'pause'])->name('cms.api.queues.pause');
                Route::post('/resume', [QueueController::class, 'resume'])->name('cms.api.queues.resume');
                Route::post('/failed/retry-all', [QueueController::class, 'retryAll'])->name('cms.api.queues.retry-all');
                Route::post('/failed/flush', [QueueController::class, 'flush'])->name('cms.api.queues.flush');
                Route::post('/failed/{id}/retry', [QueueController::class, 'retry'])
                    ->where('id', '[0-9a-f\-]+')
                    ->name('cms.api.queues.retry');
                Route::delete('/failed/{id}', [QueueController::class, 'destroy'])
                    ->where('id', '[0-9a-f\-]+')
                    ->name('cms.api.queues.destroy');
            });

            // -----------------------------------------------------------
            // Queue CRUD (manage)
            // -----------------------------------------------------------
            Route::prefix('core-settings/queues/manage')->group(function () {
                Route::get('/', [QueueController::class, 'listManaged'])->middleware('can:settings.view')->name('cms.api.queues.manage.list');
                Route::post('/', [QueueController::class, 'storeManaged'])->middleware('can:settings.edit')->name('cms.api.queues.manage.store');
                Route::put('/{id}', [QueueController::class, 'updateManaged'])->middleware('can:settings.edit')->name('cms.api.queues.manage.update');
                Route::delete('/{id}', [QueueController::class, 'destroyManaged'])->middleware('can:settings.edit')->name('cms.api.queues.manage.destroy');
            });

            // -----------------------------------------------------------
            // Schedule monitoring — read
            // -----------------------------------------------------------
            Route::prefix('core-settings/schedule')->middleware('can:settings.view')->group(function () {
                Route::get('/tasks', [ScheduleController::class, 'tasks'])->name('cms.api.schedule.tasks');
                Route::get('/commands', [ScheduleController::class, 'commands'])->name('cms.api.schedule.commands');
                Route::get('/history', [ScheduleController::class, 'history'])->name('cms.api.schedule.history');
            });

            // -----------------------------------------------------------
            // Schedule monitoring — actions
            // -----------------------------------------------------------
            Route::prefix('core-settings/schedule')->middleware(['can:settings.edit', 'throttle:10,1'])->group(function () {
                Route::post('/tasks/{command}/run', [ScheduleController::class, 'run'])
                    ->where('command', '[a-z0-9:\-]+')
                    ->name('cms.api.schedule.run');
                Route::post('/tasks', [ScheduleController::class, 'storeTask'])->name('cms.api.schedule.tasks.store');
                Route::put('/tasks/{id}', [ScheduleController::class, 'updateTask'])->name('cms.api.schedule.tasks.update');
                Route::delete('/tasks/{id}', [ScheduleController::class, 'destroyTask'])->name('cms.api.schedule.tasks.destroy');
            });

            // -----------------------------------------------------------
            // Global Settings (Глобальные настройки)
            // -----------------------------------------------------------
            Route::get('/settings', [GlobalSettingsController::class, 'index'])->name('cms.api.settings.index');
            Route::put('/settings', [GlobalSettingsController::class, 'saveValues'])->name('cms.api.settings.update');
            Route::put('/settings/values', [GlobalSettingsController::class, 'saveValues'])->name('cms.api.settings.values');

            // Preview Wrapper (Шаблон превью)
            Route::get('/settings/preview-wrapper', [GlobalSettingsController::class, 'getPreviewWrapper'])->name('cms.api.settings.preview-wrapper');
            Route::put('/settings/preview-wrapper', [GlobalSettingsController::class, 'savePreviewWrapper'])->name('cms.api.settings.preview-wrapper.save');
            Route::delete('/settings/preview-wrapper', [GlobalSettingsController::class, 'resetPreviewWrapper'])->name('cms.api.settings.preview-wrapper.reset');

            // Settings Pages (Вкладки настроек)
            Route::get('/settings/pages', [GlobalSettingsController::class, 'pages'])->name('cms.api.settings.pages');
            Route::post('/settings/pages', [GlobalSettingsController::class, 'storePage'])->name('cms.api.settings.pages.store');
            Route::put('/settings/pages/reorder', [GlobalSettingsController::class, 'reorderPages'])->name('cms.api.settings.pages.reorder');
            Route::put('/settings/pages/{id}', [GlobalSettingsController::class, 'updatePage'])->name('cms.api.settings.pages.update');
            Route::delete('/settings/pages/{id}', [GlobalSettingsController::class, 'destroyPage'])->name('cms.api.settings.pages.destroy');

            // Settings Sections (Секции настроек)
            Route::post('/settings/sections', [GlobalSettingsController::class, 'storeSection'])->name('cms.api.settings.sections.store');
            Route::put('/settings/sections/reorder', [GlobalSettingsController::class, 'reorderSections'])->name('cms.api.settings.sections.reorder');
            Route::put('/settings/sections/{id}', [GlobalSettingsController::class, 'updateSection'])->name('cms.api.settings.sections.update');
            Route::delete('/settings/sections/{id}', [GlobalSettingsController::class, 'destroySection'])->name('cms.api.settings.sections.destroy');

            // Settings Fields (Поля настроек)
            Route::post('/settings/fields', [GlobalSettingsController::class, 'storeField'])->name('cms.api.settings.fields.store');
            Route::put('/settings/fields/reorder', [GlobalSettingsController::class, 'reorderFields'])->name('cms.api.settings.fields.reorder');
            Route::put('/settings/fields/{id}', [GlobalSettingsController::class, 'updateField'])->name('cms.api.settings.fields.update');
            Route::delete('/settings/fields/{id}', [GlobalSettingsController::class, 'destroyField'])->name('cms.api.settings.fields.destroy');

            // -----------------------------------------------------------
            // Media Folders (Папки медиафайлов) — before /media/{id} to avoid route collision
            // -----------------------------------------------------------
            Route::get('/media/folders', [MediaController::class, 'folders'])->name('cms.api.media.folders.index');
            Route::post('/media/folders', [MediaController::class, 'storeFolder'])->name('cms.api.media.folders.store');
            Route::put('/media/folders/{id}', [MediaController::class, 'updateFolder'])->name('cms.api.media.folders.update');
            Route::delete('/media/folders/{id}', [MediaController::class, 'destroyFolder'])->name('cms.api.media.folders.destroy');

            // -----------------------------------------------------------
            // Media (Медиафайлы)
            // -----------------------------------------------------------
            Route::get('/media', [MediaController::class, 'index'])->name('cms.api.media.index');
            Route::post('/media/upload', [MediaController::class, 'upload'])->middleware(['cms.optimize_images', 'throttle:10,1'])->name('cms.api.media.upload');
            Route::get('/media/serve/{id}', [MediaController::class, 'serve'])->name('cms.api.media.serve');
            Route::post('/media/move', [MediaController::class, 'move'])->name('cms.api.media.move');
            Route::post('/media/delete-many', [MediaController::class, 'deleteMany'])->name('cms.api.media.delete-many');
            Route::post('/media/{id}/reprocess', [MediaController::class, 'reprocess'])->name('cms.api.media.reprocess');
            Route::post('/media/{id}/sizes', [MediaController::class, 'createSize'])->name('cms.api.media.sizes.store');
            Route::delete('/media/{id}/sizes/{sizeName}', [MediaController::class, 'deleteSize'])->name('cms.api.media.sizes.destroy');
            Route::get('/media/{id}', [MediaController::class, 'show'])->name('cms.api.media.show');
            Route::put('/media/{id}', [MediaController::class, 'update'])->name('cms.api.media.update');
            Route::delete('/media/{id}', [MediaController::class, 'destroy'])->name('cms.api.media.destroy');

            // -----------------------------------------------------------
            // Managers (Менеджеры — требует permission)
            // -----------------------------------------------------------
            Route::middleware('can:managers.view')->group(function () {
                Route::get('/managers', [ManagerController::class, 'index'])->name('cms.api.managers.index');
                Route::get('/managers/{id}', [ManagerController::class, 'show'])->name('cms.api.managers.show');
                Route::get('/managers/{id}/sessions', [ManagerController::class, 'sessions'])->name('cms.api.managers.sessions');
            });
            Route::post('/managers', [ManagerController::class, 'store'])->middleware('can:managers.create')->name('cms.api.managers.store');
            Route::put('/managers/{id}', [ManagerController::class, 'update'])->middleware('can:managers.edit')->name('cms.api.managers.update');
            Route::delete('/managers/{id}', [ManagerController::class, 'destroy'])->middleware('can:managers.delete')->name('cms.api.managers.destroy');
            Route::middleware('can:managers.edit')->group(function () {
                Route::delete('/managers/{id}/sessions/expired', [ManagerController::class, 'terminateExpiredSessions'])->name('cms.api.managers.sessions.terminate-expired');
                Route::delete('/managers/{id}/sessions', [ManagerController::class, 'terminateAllSessions'])->name('cms.api.managers.sessions.terminate-all');
                Route::delete('/managers/{id}/sessions/{sessionId}', [ManagerController::class, 'terminateSession'])->name('cms.api.managers.sessions.terminate');
                Route::delete('/managers/{id}/two-factor', [TwoFactorController::class, 'resetForManager'])->name('cms.api.managers.two-factor.reset');
            });

            // -----------------------------------------------------------
            // Manager Types (Типы менеджеров — требует permission managers.*)
            // -----------------------------------------------------------
            Route::middleware('can:managers.view')->group(function () {
                Route::get('/manager-types', [ManagerTypeController::class, 'index'])->name('cms.api.manager-types.index');
                Route::get('/manager-types/{id}', [ManagerTypeController::class, 'show'])->name('cms.api.manager-types.show');
            });
            Route::middleware('can:managers.edit')->group(function () {
                Route::post('/manager-types', [ManagerTypeController::class, 'store'])->name('cms.api.manager-types.store');
                Route::put('/manager-types/{id}', [ManagerTypeController::class, 'update'])->name('cms.api.manager-types.update');
                Route::delete('/manager-types/{id}', [ManagerTypeController::class, 'destroy'])->name('cms.api.manager-types.destroy');
            });

            // -----------------------------------------------------------
            // Logs (Логи действий)
            // -----------------------------------------------------------
            Route::get('/logs', [LogController::class, 'index'])->name('cms.api.logs.index');

            // -----------------------------------------------------------
            // File Manager (Файловый менеджер public/ — требует permission)
            // -----------------------------------------------------------
            Route::middleware('can:files.manage')->prefix('file-manager')->group(function () {
                Route::get('/', [FileManagerController::class, 'index'])->name('cms.api.file-manager.index');
                Route::get('/file', [FileManagerController::class, 'showFile'])->name('cms.api.file-manager.show');
                Route::put('/file', [FileManagerController::class, 'updateFile'])->name('cms.api.file-manager.update');
                Route::post('/upload', [FileManagerController::class, 'upload'])->middleware('throttle:10,1')->name('cms.api.file-manager.upload');
                Route::post('/folder', [FileManagerController::class, 'createFolder'])->name('cms.api.file-manager.folder');
                Route::post('/create-file', [FileManagerController::class, 'createFile'])->name('cms.api.file-manager.create-file');
                Route::post('/delete', [FileManagerController::class, 'deleteFile'])->name('cms.api.file-manager.delete');
                Route::patch('/rename', [FileManagerController::class, 'rename'])->name('cms.api.file-manager.rename');
                Route::patch('/move', [FileManagerController::class, 'move'])->name('cms.api.file-manager.move');
                Route::get('/download', [FileManagerController::class, 'download'])->name('cms.api.file-manager.download');
            });

            // -----------------------------------------------------------
            // Libraries (Библиотеки)
            // -----------------------------------------------------------
            Route::get('/libraries', [LibraryController::class, 'index'])->name('cms.api.libraries.index');
            Route::post('/libraries', [LibraryController::class, 'store'])->name('cms.api.libraries.store');
            Route::get('/libraries/{id}', [LibraryController::class, 'show'])->name('cms.api.libraries.show');
            Route::put('/libraries/{id}', [LibraryController::class, 'update'])->name('cms.api.libraries.update');
            Route::delete('/libraries/{id}', [LibraryController::class, 'destroy'])->name('cms.api.libraries.destroy');
            Route::post('/libraries/{id}/upload', [LibraryController::class, 'upload'])->middleware('throttle:10,1')->name('cms.api.libraries.upload');

            // -----------------------------------------------------------
            // Cities (Города)
            // -----------------------------------------------------------
            Route::get('/cities', [CityController::class, 'index'])->name('cms.api.cities.index');
            Route::post('/cities', [CityController::class, 'store'])->name('cms.api.cities.store');
            Route::put('/cities/reorder', [CityController::class, 'reorder'])->name('cms.api.cities.reorder');
            Route::post('/cities/import', [CityController::class, 'import'])->name('cms.api.cities.import');
            Route::get('/cities/{id}', [CityController::class, 'show'])->name('cms.api.cities.show');
            Route::put('/cities/{id}', [CityController::class, 'update'])->name('cms.api.cities.update');
            Route::delete('/cities/{id}', [CityController::class, 'destroy'])->name('cms.api.cities.destroy');

            // -----------------------------------------------------------
            // City Pages (Городские оверрайды страниц)
            // -----------------------------------------------------------
            Route::get('/pages/{pageId}/cities', [CityPageController::class, 'index'])->name('cms.api.city-pages.index');
            Route::get('/pages/{pageId}/cities/{cityId}', [CityPageController::class, 'show'])->name('cms.api.city-pages.show');
            Route::put('/pages/{pageId}/cities/{cityId}', [CityPageController::class, 'update'])->name('cms.api.city-pages.update');
            Route::put('/city-pages/{cityPageId}/blocks', [CityPageController::class, 'updateBlocks'])->name('cms.api.city-pages.blocks.update');
            Route::post('/pages/{pageId}/cities/{cityId}/materialize', [CityPageController::class, 'materialize'])->name('cms.api.city-pages.materialize');
            Route::post('/pages/{pageId}/cities/{cityId}/dematerialize', [CityPageController::class, 'dematerialize'])->name('cms.api.city-pages.dematerialize');

            // -----------------------------------------------------------
            // Languages (Языки)
            // -----------------------------------------------------------
            Route::get('/languages', [LanguageController::class, 'index'])->name('cms.api.languages.index');
            Route::post('/languages', [LanguageController::class, 'store'])->name('cms.api.languages.store');
            Route::put('/languages/reorder', [LanguageController::class, 'reorder'])->name('cms.api.languages.reorder');
            Route::get('/languages/{id}', [LanguageController::class, 'show'])->name('cms.api.languages.show');
            Route::put('/languages/{id}', [LanguageController::class, 'update'])->name('cms.api.languages.update');
            Route::delete('/languages/{id}', [LanguageController::class, 'destroy'])->name('cms.api.languages.destroy');
            Route::put('/languages/{id}/set-default', [LanguageController::class, 'setDefault'])->name('cms.api.languages.set-default');

            // -----------------------------------------------------------
            // Translations (Переводы)
            // -----------------------------------------------------------
            Route::get('/pages/{page}/translations/{lang}', [TranslationController::class, 'getPageTranslation'])->name('cms.api.translations.page.show');
            Route::put('/pages/{page}/translations/{lang}', [TranslationController::class, 'savePageTranslation'])->name('cms.api.translations.page.save');
            Route::get('/pages/{page}/block-translations/{lang}', [TranslationController::class, 'getPageBlockTranslations'])->name('cms.api.translations.page-blocks.index');
            Route::put('/pages/{page}/block-translations/{lang}', [TranslationController::class, 'savePageBlockTranslations'])->name('cms.api.translations.page-blocks.save');
            Route::get('/page-blocks/{pageBlock}/translations/{lang}', [TranslationController::class, 'getBlockTranslation'])->name('cms.api.translations.block.show');
            Route::put('/page-blocks/{pageBlock}/translations/{lang}', [TranslationController::class, 'saveBlockTranslation'])->name('cms.api.translations.block.save');
            Route::post('/page-blocks/{pageBlock}/translations/{lang}/copy-from-default', [TranslationController::class, 'copyBlockFromDefault'])->name('cms.api.translations.block.copy');
            Route::get('/global-settings/translations/{lang}', [TranslationController::class, 'getGlobalTranslations'])->name('cms.api.translations.global.show');
            Route::put('/global-settings/translations/{lang}', [TranslationController::class, 'saveGlobalTranslations'])->name('cms.api.translations.global.save');

            // -----------------------------------------------------------
            // Export / Import (Импорт / Экспорт)
            // -----------------------------------------------------------
            Route::get('/export/entities', [ExportImportController::class, 'entities']);
            Route::post('/export/preview', [ExportImportController::class, 'preview'])->middleware('throttle:10,1');
            Route::post('/export/run', [ExportImportController::class, 'run'])->middleware('throttle:10,1');
            Route::post('/import/upload', [ExportImportController::class, 'upload'])->middleware('throttle:10,1');
            Route::post('/import/run', [ExportImportController::class, 'importRun'])->middleware('throttle:10,1');
            Route::get('/export-import/log', [ExportImportController::class, 'log']);
            Route::get('/export-import/log/{id}/download', [ExportImportController::class, 'downloadLog']);

            // -----------------------------------------------------------
            // Cache & Assets (Кэш и компиляция ассетов)
            // -----------------------------------------------------------
            Route::post('/cache/clear', [CacheAssetController::class, 'clearCache'])->middleware('throttle:10,1')->name('cms.api.cache.clear');
            Route::post('/assets/compile/{pageId}', [CacheAssetController::class, 'compilePage'])->middleware('throttle:10,1')->name('cms.api.assets.compile');
            Route::post('/assets/compile-all', [CacheAssetController::class, 'compileAll'])->middleware('throttle:10,1')->name('cms.api.assets.compile-all');
            Route::post('/assets/rebuild', [CacheAssetController::class, 'rebuild'])->middleware('throttle:10,1')->name('cms.api.assets.rebuild');
        });
    });
