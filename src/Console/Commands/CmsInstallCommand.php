<?php

namespace Templite\Cms\Console\Commands;

use Illuminate\Console\Command;
use Templite\Cms\CmsInstaller;

/**
 * Artisan: cms:install
 *
 * Инициализация CMS: публикация конфигов, создание директорий,
 * миграции, создание суперадмина.
 */
class CmsInstallCommand extends Command
{
    protected $signature = 'cms:install
        {--fresh : Пересоздать таблицы (migrate:fresh)}
        {--skip-migrate : Не запускать миграции}';

    protected $description = 'Установка Templite CMS';

    public function handle(CmsInstaller $installer): int
    {
        $this->info('');
        $this->info('  ╔══════════════════════════════════╗');
        $this->info('  ║    Templite CMS -- Установка     ║');
        $this->info('  ╚══════════════════════════════════╝');
        $this->info('');

        // 1. Очистка дефолтных Laravel routes (CMS регистрирует свои)
        $this->task('Очистка дефолтных маршрутов', function () {
            $this->cleanDefaultRoutes();
            return true;
        });

        // 2. Публикация конфигов
        $this->task('Публикация конфигурации', function () {
            $this->callSilent('vendor:publish', [
                '--tag' => 'cms-config',
                '--force' => true,
            ]);
            return true;
        });

        // 3. Создание директорий
        $this->task('Создание директорий', function () use ($installer) {
            $installer->createDirectories();
            return true;
        });

        // 4. Миграции
        if (!$this->option('skip-migrate')) {
            $this->task('Запуск миграций', function () {
                if ($this->option('fresh')) {
                    $code = $this->callSilent('migrate:fresh', ['--force' => true]);
                } else {
                    $code = $this->callSilent('migrate', ['--force' => true]);
                }
                return $code === 0;
            });
        }

        // 5. Создание суперадмина (интерактивно)
        $this->info('');
        if ($this->confirm('Создать суперадмина?', true)) {
            $login = $this->ask('Логин', 'admin');
            $password = $this->secret('Пароль (мин. 6 символов)');

            if (!$password || strlen($password) < 6) {
                $this->error('Пароль должен быть не менее 6 символов.');
                return self::FAILURE;
            }

            $installer->createSuperAdmin($login, $password);
            $this->info("  Суперадмин '{$login}' создан.");
        }

        // 8. Символическая ссылка storage
        $this->task('Символическая ссылка storage', function () {
            $this->callSilent('storage:link');
            return true;
        });

        // 9. Установить флаг APP_INSTALLED=true в .env
        $this->task('Установка флага APP_INSTALLED', function () {
            $this->setEnvInstalled();
            return true;
        });

        // 10. Удаление install.php (безопасность — H-06)
        $this->task('Удаление install.php', function () {
            return $this->removeInstallScript();
        });

        $this->info('');
        $this->info('  Templite CMS успешно установлена!');
        $this->info('  Админка: ' . url(config('cms.admin_url', 'admin')));
        $this->info('');

        return self::SUCCESS;
    }

    /**
     * Очищает дефолтные Laravel routes/web.php и удаляет welcome.blade.php.
     * CMS регистрирует собственные маршруты через ServiceProvider.
     */
    protected function cleanDefaultRoutes(): void
    {
        $webRoutes = base_path('routes/web.php');

        if (file_exists($webRoutes)) {
            $content = file_get_contents($webRoutes);

            // Заменяем только если это дефолтный Laravel route (welcome view)
            if (str_contains($content, "view('welcome')") || str_contains($content, "view(\"welcome\")")) {
                file_put_contents($webRoutes, "<?php\n\n// CMS routes are registered by Templite\\Cms\\CmsServiceProvider.\n");
            }
        }

        // Удаляем дефолтный welcome.blade.php (CMS использует свой layout)
        $welcomeView = resource_path('views/welcome.blade.php');
        if (file_exists($welcomeView)) {
            unlink($welcomeView);
        }

        // Настраиваем bootstrap/app.php (statefulApi для Sanctum SPA auth)
        $this->patchBootstrapApp();
    }

    /**
     * Патчит bootstrap/app.php: добавляет statefulApi() и throttleApi() если отсутствуют.
     */
    protected function patchBootstrapApp(): void
    {
        $file = base_path('bootstrap/app.php');
        if (!file_exists($file)) {
            return;
        }

        $content = file_get_contents($file);

        if (str_contains($content, 'statefulApi')) {
            return; // уже настроено
        }

        // Заменяем пустой withMiddleware на настроенный
        $content = preg_replace(
            '/->withMiddleware\(function\s*\(Middleware\s+\$middleware\)\s*:\s*void\s*\{\s*\/\/\s*\}\)/',
            '->withMiddleware(function (Middleware $middleware): void {' . "\n"
            . '        $middleware->statefulApi();' . "\n"
            . '        $middleware->throttleApi(\'60,1\');' . "\n"
            . '    })',
            $content
        );

        file_put_contents($file, $content);
    }

    /**
     * Устанавливает APP_INSTALLED=true в .env файле.
     * Если ключ уже есть — обновляет значение, если нет — добавляет.
     */
    protected function setEnvInstalled(): void
    {
        $envFile = base_path('.env');
        if (!file_exists($envFile)) {
            return;
        }

        $content = file_get_contents($envFile);

        if (preg_match('/^\s*APP_INSTALLED\s*=/m', $content)) {
            $content = preg_replace(
                '/^\s*APP_INSTALLED\s*=.*$/m',
                'APP_INSTALLED=true',
                $content
            );
        } else {
            $content .= "\nAPP_INSTALLED=true\n";
        }

        file_put_contents($envFile, $content);
    }

    /**
     * Удаляет install.php из public-директории после успешной установки.
     * Это критическая мера безопасности (H-06): инсталлятор не должен
     * быть доступен после установки CMS.
     */
    protected function removeInstallScript(): bool
    {
        $installFile = public_path('install.php');

        if (!file_exists($installFile)) {
            return true; // уже удалён
        }

        if (@unlink($installFile)) {
            return true;
        }

        // Если не удалось удалить (права доступа), предупредить
        $this->warn('  Не удалось удалить install.php автоматически.');
        $this->warn('  ВАЖНО: Удалите файл вручную: rm ' . $installFile);
        return false;
    }

    /**
     * Выполнить задачу с визуальным индикатором.
     */
    protected function task(string $title, callable $callback): void
    {
        $this->output->write("  {$title}...");

        try {
            $result = $callback();
            $this->output->writeln($result ? ' <info>OK</info>' : ' <error>FAILED</error>');
        } catch (\Throwable $e) {
            $this->output->writeln(' <error>ERROR: ' . $e->getMessage() . '</error>');
        }
    }
}
