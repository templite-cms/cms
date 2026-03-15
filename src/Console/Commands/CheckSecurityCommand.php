<?php

namespace Templite\Cms\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Templite\Cms\Models\Manager;

/**
 * Artisan: cms:check-security
 *
 * Проверка безопасности CMS: дефолтные пароли, небезопасные настройки.
 */
class CheckSecurityCommand extends Command
{
    protected $signature = 'cms:check-security';

    protected $description = 'Проверка безопасности CMS (дефолтные пароли, небезопасные настройки)';

    /**
     * Пароли по умолчанию, которые считаются небезопасными.
     */
    protected array $defaultPasswords = [
        'admin123',
        'password',
        '123456',
        'admin',
    ];

    public function handle(): int
    {
        $this->info('Проверка безопасности CMS...');
        $this->newLine();

        $hasIssues = false;

        // 1. Проверка менеджеров с дефолтными паролями
        $hasIssues = $this->checkDefaultPasswords() || $hasIssues;

        // 2. Проверка APP_DEBUG в production
        $hasIssues = $this->checkDebugMode() || $hasIssues;

        // 3. Проверка APP_KEY
        $hasIssues = $this->checkAppKey() || $hasIssues;

        $this->newLine();

        if ($hasIssues) {
            $this->error('Обнаружены проблемы безопасности! Устраните их перед использованием в production.');
            return self::FAILURE;
        }

        $this->info('Проблем безопасности не обнаружено.');
        return self::SUCCESS;
    }

    /**
     * Проверить менеджеров на использование паролей по умолчанию.
     */
    protected function checkDefaultPasswords(): bool
    {
        try {
            $managers = Manager::all(['id', 'login', 'password']);
        } catch (\Throwable $e) {
            $this->warn('  Не удалось проверить менеджеров (таблица ещё не создана?)');
            return false;
        }

        $vulnerableManagers = [];

        foreach ($managers as $manager) {
            foreach ($this->defaultPasswords as $defaultPassword) {
                if (Hash::check($defaultPassword, $manager->password)) {
                    $vulnerableManagers[] = [
                        'id' => $manager->id,
                        'login' => $manager->login,
                        'default_password' => $defaultPassword,
                    ];
                    break;
                }
            }
        }

        if (empty($vulnerableManagers)) {
            $this->info('  [OK] Менеджеры с дефолтными паролями не найдены.');
            return false;
        }

        $this->warn('  [WARN] Найдены менеджеры с паролями по умолчанию:');

        $this->table(
            ['ID', 'Логин', 'Дефолтный пароль'],
            array_map(fn ($m) => [$m['id'], $m['login'], str_repeat('*', strlen($m['default_password']))], $vulnerableManagers)
        );

        $this->warn('  Смените пароли этих менеджеров через админку или artisan tinker.');

        return true;
    }

    /**
     * Проверить, не включен ли debug-режим в production.
     */
    protected function checkDebugMode(): bool
    {
        if (app()->environment('production') && config('app.debug')) {
            $this->warn('  [WARN] APP_DEBUG=true в production-окружении. Отключите для безопасности.');
            return true;
        }

        $this->info('  [OK] Debug-режим корректно настроен.');
        return false;
    }

    /**
     * Проверить, установлен ли APP_KEY.
     */
    protected function checkAppKey(): bool
    {
        $key = config('app.key');

        if (empty($key) || $key === 'base64:') {
            $this->warn('  [WARN] APP_KEY не установлен. Выполните: php artisan key:generate');
            return true;
        }

        $this->info('  [OK] APP_KEY установлен.');
        return false;
    }
}
