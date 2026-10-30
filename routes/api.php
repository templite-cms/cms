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
use Templite\Cms\Http\Controllers\Api\BlockCodeController;
use Templite\Cms\Http\Controllers\Api\ActionController;
use Templite\Cms\Http\Controllers\Api\BlockActionController;
use Templite\Cms\Http\Controllers\Api\ComponentController;
use Templite\Cms\Http\Controllers\Api\ComponentCodeController;
use Templite\Cms\Http\Controllers\Api\TemplateController;
use Templite\Cms\Http\Controllers\Api\TemplateCodeController;
use Templite\Cms\Http\Controllers\Api\TemplateFieldController;
use Templite\Cms\Http\Controllers\Api\GlobalSettingsController;
use Templite\Cms\Http\Controllers\Api\MediaController;
use Templite\Cms\Http\Controllers\Api\ManagerController;
use Templite\Cms\Http\Controllers\Api\ManagerTypeController;
use Templite\Cms\Http\Controllers\Api\LogController;
use Templite\Cms\Http\Controllers\Api\FileManagerController;
use Templite\Cms\Http\Controllers\Api\CoreSettingsController;
use Templite\Cms\Http\Controllers\Api\DashboardWidgetController;
use Templite\Cms\Http\Controllers\Api\LibraryController;
use Templite\Cms\Http\Controllers\Api\CdnjsCatalogController;
use Templite\Cms\Http\Controllers\Api\CacheAssetController;
use Templite\Cms\Http\Controllers\Api\BlockPresetController;
use Templite\Cms\Http\Controllers\Api\ExportImportController;
use Templite\Cms\Http\Controllers\Api\TwoFactorController;
use Templite\Cms\Http\Controllers\Api\QueueController;
use Templite\Cms\Http\Controllers\Api\ScheduleController;
use Templite\Cms\Http\Controllers\Api\McpController;
use Templite\Cms\Http\Controllers\Api\McpTokenController;
use Templite\Cms\Http\Middleware\EnsureMcpToken;

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

            // Дашборд: фильтр и подгрузка виджета; право проверяет сам виджет
            Route::get('/dashboard/widgets/{slug}', [DashboardWidgetController::class, 'show'])
                ->where('slug', '[a-z0-9.\-]+')
                ->name('cms.api.dashboard.widget');

            // -----------------------------------------------------------
            // API Tokens
            // -----------------------------------------------------------
            Route::get('/tokens', [ApiTokenController::class, 'index'])->name('cms.api.tokens.index');
            Route::post('/tokens', [ApiTokenController::class, 'store'])->name('cms.api.tokens.store');
            Route::put('/tokens/{id}', [ApiTokenController::class, 'update'])->whereNumber('id')->name('cms.api.tokens.update');
            Route::delete('/tokens/{id}', [ApiTokenController::class, 'destroy'])->name('cms.api.tokens.destroy');

            // -----------------------------------------------------------
            // MCP-шлюз (только личный MCP-токен, см. EnsureMcpToken)
            // -----------------------------------------------------------
            Route::prefix('mcp')->middleware(EnsureMcpToken::class)->group(function () {
                Route::get('/manifest', [McpController::class, 'manifest'])->name('cms.api.mcp.manifest');
                Route::post('/call', [McpController::class, 'call'])->name('cms.api.mcp.call');
                Route::get('/prompts/{name}', [McpController::class, 'prompt'])->where('name', '[a-z0-9-]+')->name('cms.api.mcp.prompt');
            });

            // Имена cms.api.mcp-tokens.* вне префикса cms.api.mcp. — MCP-токеном токены не выпустить.
            Route::middleware('can:mcp.use')->group(function () {
                Route::get('/mcp-tokens', [McpTokenController::class, 'index'])->name('cms.api.mcp-tokens.index');
                Route::post('/mcp-tokens', [McpTokenController::class, 'store'])->name('cms.api.mcp-tokens.store');
                Route::put('/mcp-tokens/{id}', [McpTokenController::class, 'update'])->whereNumber('id')->name('cms.api.mcp-tokens.update');
                Route::delete('/mcp-tokens/{id}', [McpTokenController::class, 'destroy'])->whereNumber('id')->name('cms.api.mcp-tokens.destroy');
            });

            // -----------------------------------------------------------
            // Pages (Страницы)
            // -----------------------------------------------------------
            Route::get('/pages', [PageController::class, 'index'])->name('cms.api.pages.index');
            Route::get('/pages/tree', [PageController::class, 'tree'])->name('cms.api.pages.tree');
            Route::get('/pages/handlers', [PageController::class, 'handlers'])->name('cms.api.pages.handlers');
            Route::post('/pages', [PageController::class, 'store'])->name('cms.api.pages.store');
            Route::get('/pages/{id}', [PageController::class, 'show'])->name('cms.api.pages.show');
            Route::put('/pages/{id}', [PageController::class, 'update'])->name('cms.api.pages.update');
            Route::delete('/pages/{id}', [PageController::class, 'destroy'])->name('cms.api.pages.destroy');
            Route::post('/pages/{id}/copy', [PageController::class, 'copy'])->name('cms.api.pages.copy');
            Route::put('/pages/reorder', [PageController::class, 'reorder'])->name('cms.api.pages.reorder');
            Route::post('/pages/bulk', [PageController::class, 'bulk'])->name('cms.api.pages.bulk');
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
            Route::put('/page-blocks/{id}/toggle-cache', [PageBlockController::class, 'toggleCache'])->name('cms.api.page-blocks.toggle-cache');
            Route::post('/page-blocks/{id}/invalidate-cache', [PageBlockController::class, 'invalidateCache'])->name('cms.api.page-blocks.invalidate-cache');
            Route::post('/page-blocks/{id}/render', [PageBlockController::class, 'render'])->middleware('cms.global_fields')->name('cms.api.page-blocks.render');

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
            // Block Code (Код блоков: template, style, script)
            // -----------------------------------------------------------
            Route::get('/blocks/{id}/code', [BlockCodeController::class, 'show'])->name('cms.api.block-code.show');
            Route::put('/blocks/{id}/code', [BlockCodeController::class, 'update'])->middleware(['can:blocks.code', 'throttle:10,1'])->name('cms.api.block-code.update');
            Route::get('/blocks/{id}/code/versions', [BlockCodeController::class, 'versions'])->middleware('can:blocks.code')->name('cms.api.block-code.versions');
            Route::get('/blocks/{id}/code/versions/{versionId}', [BlockCodeController::class, 'version'])->middleware('can:blocks.code')->name('cms.api.block-code.version');
            Route::post('/blocks/{id}/code/versions/{versionId}/restore', [BlockCodeController::class, 'restore'])->middleware(['can:blocks.code', 'throttle:10,1'])->name('cms.api.block-code.restore');

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
            Route::match(['get', 'post'], '/components/{id}/preview', [ComponentController::class, 'preview'])->middleware('cms.global_fields')->name('cms.api.components.preview');

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
            Route::match(['get', 'post'], '/templates/{id}/preview', [TemplateCodeController::class, 'preview'])->middleware('cms.global_fields')->name('cms.api.template-code.preview');

            // -----------------------------------------------------------
            // Template Fields (Поля шаблонов)
            // -----------------------------------------------------------
            Route::get('/templates/{templateId}/fields', [TemplateFieldController::class, 'index'])->name('cms.api.template-fields.index');
            Route::post('/templates/{templateId}/fields', [TemplateFieldController::class, 'store'])->name('cms.api.template-fields.store');
            Route::put('/templates/{templateId}/fields/reorder', [TemplateFieldController::class, 'reorder'])->name('cms.api.template-fields.reorder');

            // -----------------------------------------------------------
            // AI-ассистент CMS (глобальные настройки, ключи, доступы, чат)
            // -----------------------------------------------------------
            Route::middleware('can:settings.view')->group(function () {
                Route::get('/ai/settings', [\Templite\Cms\Http\Controllers\Api\Ai\AiSettingsController::class, 'show'])->name('cms.api.ai.settings');
            });
            Route::middleware('can:settings.edit')->group(function () {
                Route::put('/ai/settings', [\Templite\Cms\Http\Controllers\Api\Ai\AiSettingsController::class, 'update'])->name('cms.api.ai.settings.update');
            });

            Route::middleware('can:ai.use')->group(function () {
                Route::get('/ai/status', [\Templite\Cms\Http\Controllers\Api\Ai\AiSettingsController::class, 'status'])->name('cms.api.ai.status');
                Route::get('/ai/models', [\Templite\Cms\Http\Controllers\Api\Ai\AiSettingsController::class, 'models'])->name('cms.api.ai.models');
                Route::get('/ai/keys', [\Templite\Cms\Http\Controllers\Api\Ai\AiKeysController::class, 'index'])->name('cms.api.ai.keys.index');
                Route::put('/ai/active-key', [\Templite\Cms\Http\Controllers\Api\Ai\AiKeysController::class, 'setActive'])->name('cms.api.ai.active-key');

                Route::post('/ai/chat', \Templite\Cms\Http\Controllers\Api\Ai\AiChatController::class)->name('cms.api.ai.chat');
                Route::get('/ai/sessions', [\Templite\Cms\Http\Controllers\Api\Ai\AiSessionsController::class, 'index'])->name('cms.api.ai.sessions.index');
                Route::post('/ai/sessions', [\Templite\Cms\Http\Controllers\Api\Ai\AiSessionsController::class, 'store'])->name('cms.api.ai.sessions.store');
                Route::get('/ai/sessions/{id}', [\Templite\Cms\Http\Controllers\Api\Ai\AiSessionsController::class, 'show'])->name('cms.api.ai.sessions.show');
                Route::put('/ai/sessions/{id}', [\Templite\Cms\Http\Controllers\Api\Ai\AiSessionsController::class, 'update'])->name('cms.api.ai.sessions.update');
                Route::delete('/ai/sessions/{id}', [\Templite\Cms\Http\Controllers\Api\Ai\AiSessionsController::class, 'destroy'])->name('cms.api.ai.sessions.destroy');
                Route::post('/ai/images/{id}/approve', [\Templite\Cms\Http\Controllers\Api\Ai\AiImagesController::class, 'approve'])->name('cms.api.ai.images.approve');
                Route::delete('/ai/images/{id}', [\Templite\Cms\Http\Controllers\Api\Ai\AiImagesController::class, 'reject'])->name('cms.api.ai.images.reject');
                Route::get('/ai/runs/{id}/events', [\Templite\Cms\Http\Controllers\Api\Ai\AiRunsController::class, 'events'])->name('cms.api.ai.runs.events');
                Route::post('/ai/runs/{id}/cancel', [\Templite\Cms\Http\Controllers\Api\Ai\AiRunsController::class, 'cancel'])->name('cms.api.ai.runs.cancel');
                Route::delete('/ai/runs/{id}', [\Templite\Cms\Http\Controllers\Api\Ai\AiRunsController::class, 'destroy'])->name('cms.api.ai.runs.destroy');
                Route::get('/ai/editor-ops', [\Templite\Cms\Http\Controllers\Api\Ai\AiEditorOpsController::class, 'index'])->name('cms.api.ai.editor-ops.index');
                Route::post('/ai/editor-ops/{id}/claim', [\Templite\Cms\Http\Controllers\Api\Ai\AiEditorOpsController::class, 'claim'])->name('cms.api.ai.editor-ops.claim');
            });

            // Правка и раздача ключа проверяются по владельцу внутри контроллера.
            Route::middleware('can:ai.keys.manage')->group(function () {
                Route::post('/ai/keys', [\Templite\Cms\Http\Controllers\Api\Ai\AiKeysController::class, 'store'])->name('cms.api.ai.keys.store');
                Route::put('/ai/keys/{id}', [\Templite\Cms\Http\Controllers\Api\Ai\AiKeysController::class, 'update'])->name('cms.api.ai.keys.update');
                Route::delete('/ai/keys/{id}', [\Templite\Cms\Http\Controllers\Api\Ai\AiKeysController::class, 'destroy'])->name('cms.api.ai.keys.destroy');
                Route::get('/ai/keys/{id}/access', [\Templite\Cms\Http\Controllers\Api\Ai\AiKeysController::class, 'access'])->name('cms.api.ai.keys.access');
                Route::post('/ai/keys/{id}/access', [\Templite\Cms\Http\Controllers\Api\Ai\AiKeysController::class, 'grant'])->name('cms.api.ai.keys.access.grant');
                Route::delete('/ai/keys/{id}/access/{managerId}', [\Templite\Cms\Http\Controllers\Api\Ai\AiKeysController::class, 'revoke'])->name('cms.api.ai.keys.access.revoke');
                Route::get('/ai/managers', [\Templite\Cms\Http\Controllers\Api\Ai\AiKeysController::class, 'managers'])->name('cms.api.ai.managers');
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

            // Перечитывание экранов настроек после структурных правок ассистента:
            // form — данные Settings/Index (вкладки + значения), structure — Settings/Structure
            Route::get('/settings/form', [GlobalSettingsController::class, 'form'])->name('cms.api.settings.form');
            Route::get('/settings/structure', [GlobalSettingsController::class, 'structure'])->name('cms.api.settings.structure');

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
            // throttle на store/update стоит ради загрузки файлов: отдельного
            // эндпоинта /upload больше нет, файлы приходят сюда. Лимит 30, а не
            // 10, потому что эти же эндпоинты обслуживают обычное
            // редактирование метаданных библиотеки
            Route::post('/libraries', [LibraryController::class, 'store'])->middleware('throttle:30,1')->name('cms.api.libraries.store');
            // Каталог cdnjs. Только чтение; объявлены до /libraries/{id},
            // иначе слово catalog попадёт в параметр id.
            // throttle стоит не ради записи, а потому что каждый вызов бьёт во
            // внешний сервис и может стоить секунду. Лимит 60, а не 30: поиск
            // дёргается по мере ввода, и за минуту легко набирается больше 30
            Route::get('/libraries/catalog/search', [CdnjsCatalogController::class, 'search'])->middleware('throttle:60,1')->name('cms.api.libraries.catalog.search');
            Route::get('/libraries/catalog/{name}', [CdnjsCatalogController::class, 'package'])->middleware('throttle:60,1')->name('cms.api.libraries.catalog.package');
            Route::get('/libraries/catalog/{name}/{version}', [CdnjsCatalogController::class, 'files'])->middleware('throttle:60,1')->name('cms.api.libraries.catalog.files');
            Route::get('/libraries/{id}', [LibraryController::class, 'show'])->name('cms.api.libraries.show');
            Route::put('/libraries/{id}', [LibraryController::class, 'update'])->middleware('throttle:30,1')->name('cms.api.libraries.update');
            Route::delete('/libraries/{id}', [LibraryController::class, 'destroy'])->name('cms.api.libraries.destroy');
            // Скачивание идёт по идентификаторам пакета, а не по URL, поэтому
            // тот же лимит, что у остальных операций записи
            Route::post('/libraries/{id}/catalog-files', [LibraryController::class, 'catalogFiles'])->middleware('throttle:30,1')->name('cms.api.libraries.catalog-files');

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
