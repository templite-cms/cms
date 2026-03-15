<?php

namespace Templite\Cms;

use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\ServiceProvider;
use Illuminate\Routing\Router;
use Templite\Cms\Http\Middleware\AddSecurityHeaders;
use Templite\Cms\Http\Middleware\AuthManager;
use Templite\Cms\Http\Middleware\CityResolver;
use Templite\Cms\Http\Middleware\GlobalFieldsMiddleware;
use Templite\Cms\Http\Middleware\HoneypotProtection;
use Templite\Cms\Http\Middleware\LocaleResolver;
use Templite\Cms\Http\Middleware\OptimizeImages;
use Templite\Cms\Http\Middleware\Timezone;
use Templite\Cms\Services\ActionRegistry;
use Templite\Cms\Services\BlockRegistry;
use Templite\Cms\Services\CacheManager;
use Templite\Cms\Services\ComponentRegistry;
use Templite\Cms\Services\ModuleRegistry;
use Templite\Cms\Modules\CmsModule;

class CmsServiceProvider extends ServiceProvider
{
    /**
     * Регистрация сервисов.
     */
    public function register(): void
    {
        // 1. Конфигурация
        $this->mergeConfigFrom(__DIR__ . '/../config/cms.php', 'cms');

        // 2. Модульная система
        $this->app->singleton(ModuleRegistry::class);
        $this->app->singleton(CmsModule::class);
        $this->app->tag([CmsModule::class], 'cms.modules');

        // 3. Синглтоны сервисов
        $this->app->singleton(CacheManager::class);
        $this->app->singleton(BlockRegistry::class);
        $this->app->singleton(ActionRegistry::class);
        $this->app->singleton(ComponentRegistry::class);
        $this->app->singleton(\Templite\Cms\Services\TwoFactorService::class);
    }

    /**
     * Загрузка сервисов.
     */
    public function boot(): void
    {
        // 0. Morph map для полиморфных полей
        Relation::enforceMorphMap([
            'block' => \Templite\Cms\Models\Block::class,
            'template_page' => \Templite\Cms\Models\TemplatePage::class,
            'manager' => \Templite\Cms\Models\Manager::class,
        ]);

        // 0. Force HTTPS if APP_URL uses https
        if (str_starts_with(config('app.url', ''), 'https://')) {
            \Illuminate\Support\Facades\URL::forceScheme('https');
        }

        // 0. Auth guard & provider для менеджеров CMS
        $this->configureAuth();

        // 0.1. Inertia root view из пакета
        \Inertia\Inertia::setRootView('cms::app');

        // 0.2. Inertia shared data
        \Inertia\Inertia::share([
            'auth' => fn () => [
                'user' => auth('manager')->user(),
                'permissions' => auth('manager')->user()?->use_personal_permissions
                    ? (auth('manager')->user()?->personal_permissions ?? [])
                    : (auth('manager')->user()?->managerType?->permissions ?? []),
            ],
            'navigation' => fn () => app(ModuleRegistry::class)->getNavigation(auth('manager')->user()),
            'modules' => fn () => app(ModuleRegistry::class)->getModuleInfo(),
            'cmsConfig' => fn () => [
                'admin_url' => \Templite\Cms\Models\CmsConfig::getAdminUrl(),
                'multicity_enabled' => (bool) \Templite\Cms\Models\CmsConfig::getValue('multicity_enabled', false),
                'two_factor_mode' => \Templite\Cms\Models\CmsConfig::getValue('two_factor_mode', config('cms.two_factor.mode', 'off')),
                'two_factor_trust_days' => (int) \Templite\Cms\Models\CmsConfig::getValue('two_factor_trust_days', config('cms.two_factor.trust_days', 0)),
                'multilang_enabled' => (bool) \Templite\Cms\Models\CmsConfig::getValue('multilang_enabled', false),
                'languages' => \Templite\Cms\Models\CmsConfig::getValue('multilang_enabled', false)
                    ? \Templite\Cms\Models\Language::active()->ordered()->get()->map(fn ($l) => [
                        'id' => $l->id,
                        'code' => $l->code,
                        'name' => $l->name,
                        'is_default' => $l->is_default,
                    ])
                    : [],
            ],
        ]);

        // 1. Миграции
        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');

        // 2. Роуты
        $this->app['config']->set('cms.admin_url', \Templite\Cms\Models\CmsConfig::getAdminUrl());
        $this->loadRoutesFrom(__DIR__ . '/../routes/api.php');
        $this->loadRoutesFrom(__DIR__ . '/../routes/admin.php');
        $this->loadRoutesFrom(__DIR__ . '/../routes/web.php');

        // 3. Views (Blade)
        $this->loadViewsFrom(__DIR__ . '/../resources/views', 'cms');

        // 3.1. Anonymous Blade components: <x-cms::slug />
        // Resolves storage/cms/components/{slug}/index.blade.php
        Blade::anonymousComponentPath(storage_path('cms/components'), 'cms');

        // 4. Middleware
        $this->registerMiddleware();

        // 5. Публикация конфигурации
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/../config/cms.php' => config_path('cms.php'),
            ], 'cms-config');

            $this->publishes([
                __DIR__ . '/../resources/views' => resource_path('views/vendor/cms'),
            ], 'cms-views');

            $this->publishes([
                __DIR__ . '/../public' => public_path('vendor/cms'),
            ], 'cms-assets');

            $this->publishes([
                __DIR__ . '/../dist/build' => public_path('build'),
            ], 'cms-build');

            // Команды
            $this->commands([
                Console\Commands\CmsInstallCommand::class,
                Console\Commands\MakeBlockCommand::class,
                Console\Commands\MakeActionCommand::class,
                Console\Commands\MakeComponentCommand::class,
                Console\Commands\ResizeImagesCommand::class,
                Console\Commands\CacheClearCommand::class,
                Console\Commands\CompileAssetsCommand::class,
                Console\Commands\ProcessScheduledPagesCommand::class,
                Console\Commands\CheckSecurityCommand::class,
                Console\Commands\CleanupExportsCommand::class,
                Console\Commands\CleanupExpiredSessionsCommand::class,
                Console\Commands\CleanupExpired2faDevicesCommand::class,
                Console\Commands\CleanupScheduleHistoryCommand::class,
                Console\Commands\RunActionCommand::class,
            ]);
        }

        // 6. Register queue stats listener
        \Illuminate\Support\Facades\Event::listen(
            \Illuminate\Queue\Events\JobProcessed::class,
            \Templite\Cms\Listeners\QueueStatsListener::class
        );

        // 6.1. Queue pause support — skip job processing when paused
        $this->app['queue']->looping(function () {
            return !\Illuminate\Support\Facades\Cache::get('cms:queue:paused', false);
        });

        // 6.2. Dynamic API rate limiter from CMS settings
        \Illuminate\Support\Facades\RateLimiter::for('cms-api', function (\Illuminate\Http\Request $request) {
            $limit = (int) \Templite\Cms\Models\CmsConfig::getValue('api_rate_limit', 120);

            return \Illuminate\Cache\RateLimiting\Limit::perMinute(max($limit, 10))
                ->by($request->user()?->id ?: $request->ip());
        });

        // 7. Расписание команд
        $this->registerSchedule();
    }

    /**
     * Настройка auth guard и provider для менеджеров CMS.
     */
    protected function configureAuth(): void
    {
        $config = $this->app['config'];

        $config->set('auth.guards.manager', [
            'driver' => 'session',
            'provider' => 'managers',
        ]);

        $config->set('auth.providers.managers', [
            'driver' => 'eloquent',
            'model' => \Templite\Cms\Models\Manager::class,
        ]);

        // Sanctum должен проверять guard 'manager' для SPA-аутентификации
        $config->set('sanctum.guard', ['manager']);

        // Гарантируем, что текущий домен приложения есть в stateful-списке Sanctum,
        // иначе EnsureFrontendRequestsAreStateful не инициализирует сессию
        $appHost = parse_url(config('app.url', ''), PHP_URL_HOST);
        if ($appHost) {
            $stateful = $config->get('sanctum.stateful', []);
            if (!in_array($appHost, $stateful)) {
                $stateful[] = $appHost;
                $config->set('sanctum.stateful', $stateful);
            }
        }
    }

    /**
     * Регистрация middleware.
     */
    protected function registerMiddleware(): void
    {
        /** @var Router $router */
        $router = $this->app->make(Router::class);

        // Middleware-алиасы
        $router->aliasMiddleware('cms.auth', AuthManager::class);
        $router->aliasMiddleware('cms.locale', LocaleResolver::class);
        $router->aliasMiddleware('cms.city_resolver', CityResolver::class);
        $router->aliasMiddleware('cms.global_fields', GlobalFieldsMiddleware::class);
        $router->aliasMiddleware('cms.optimize_images', OptimizeImages::class);
        $router->aliasMiddleware('cms.timezone', Timezone::class);
        $router->aliasMiddleware('cms.honeypot', HoneypotProtection::class);
        $router->aliasMiddleware('cms.security_headers', AddSecurityHeaders::class);
    }

    /**
     * Регистрация расписания команд.
     */
    protected function registerSchedule(): void
    {
        $this->app->booted(function () {
            try {
                if (!\Illuminate\Support\Facades\Schema::hasTable('cms_scheduled_tasks')) {
                    return;
                }

                $config = \Illuminate\Support\Facades\Cache::remember('cms:schedule_config', 300, function () {
                    return [
                        'tasks' => \Templite\Cms\Models\ScheduledTask::active()->get()->toArray(),
                        'queues' => \Illuminate\Support\Facades\Schema::hasTable('cms_queues')
                            ? \Templite\Cms\Models\Queue::active()->processViaSchedule()->ordered()->get()->toArray()
                            : [],
                    ];
                });

                $schedule = $this->app->make(\Illuminate\Console\Scheduling\Schedule::class);

                // Register scheduled tasks from DB
                foreach ($config['tasks'] as $task) {
                    $arguments = !empty($task['arguments'])
                        ? str_getcsv($task['arguments'], ' ')
                        : [];

                    $event = $schedule->command($task['command'], $arguments)
                        ->cron($task['expression']);

                    if ($task['without_overlapping']) {
                        $event->withoutOverlapping();
                    }

                    $this->wrapScheduleEvent($event, $task['command']);
                }

                // Register queue workers via schedule (for non-Docker hosting)
                foreach ($config['queues'] as $queue) {
                    $event = $schedule->command('queue:work', [
                        '--stop-when-empty',
                        '--queue=' . $queue['name'],
                        '--tries=' . $queue['tries'],
                        '--timeout=' . $queue['timeout'],
                        '--sleep=' . $queue['sleep'],
                    ])->everyMinute()->withoutOverlapping();

                    $this->wrapScheduleEvent($event, 'queue:work:' . $queue['name']);
                }
            } catch (\Throwable $e) {
                // Fresh install before migrations — silently skip
                \Illuminate\Support\Facades\Log::debug('CMS schedule registration skipped: ' . $e->getMessage());
            }
        });
    }

    protected function wrapScheduleEvent($event, string $name): void
    {
        $startTime = null;

        $event
            ->before(function () use ($name, &$startTime) {
                $startTime = microtime(true);
                \Illuminate\Support\Facades\Cache::put("schedule:running:{$name}", true, 300);
            })
            ->after(function () use ($name, &$startTime) {
                $durationMs = $startTime ? (int) ((microtime(true) - $startTime) * 1000) : 0;
                \Templite\Cms\Models\ScheduleHistory::create([
                    'command' => $name,
                    'status' => 'success',
                    'duration_ms' => $durationMs,
                    'ran_at' => now(),
                ]);
                \Illuminate\Support\Facades\Cache::forget("schedule:running:{$name}");
            })
            ->onFailure(function () use ($name, &$startTime) {
                $durationMs = $startTime ? (int) ((microtime(true) - $startTime) * 1000) : 0;
                \Templite\Cms\Models\ScheduleHistory::create([
                    'command' => $name,
                    'status' => 'fail',
                    'duration_ms' => $durationMs,
                    'error' => 'Task failed',
                    'ran_at' => now(),
                ]);
                \Illuminate\Support\Facades\Cache::forget("schedule:running:{$name}");
            });
    }
}
