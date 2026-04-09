<?php

namespace Templite\Cms\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Templite\Cms\Models\Manager;

/**
 * Artisan: cms:reset-password
 *
 * Сброс пароля менеджера админки по логину.
 * Пароль запрашивается интерактивно. Если ввод пустой — генерируется случайный.
 */
class ResetManagerPasswordCommand extends Command
{
    protected $signature = 'cms:reset-password
        {login : Логин менеджера}';

    protected $description = 'Сброс пароля менеджера CMS по логину (пароль запрашивается интерактивно)';

    public function handle(): int
    {
        $login = (string) $this->argument('login');

        $manager = Manager::where('login', $login)->first();

        if (!$manager) {
            $this->error("Менеджер с логином «{$login}» не найден.");
            return self::FAILURE;
        }

        $password = $this->secret('Новый пароль (оставьте пустым для генерации случайного)');
        $generated = false;

        if ($password === null || $password === '') {
            $password = Str::password(16, true, true, false);
            $generated = true;
        } else {
            $confirm = $this->secret('Повторите пароль');
            if ($confirm !== $password) {
                $this->error('Пароли не совпадают. Операция отменена.');
                return self::FAILURE;
            }
        }

        $manager->password = Hash::make($password);
        $manager->save();

        $this->info("Пароль для «{$manager->login}» успешно сброшен.");

        if ($generated) {
            $this->line('');
            $this->line('  Новый пароль: <fg=yellow;options=bold>' . $password . '</>');
            $this->line('');
            $this->warn('Сохраните пароль — он больше не будет показан.');
        }

        return self::SUCCESS;
    }
}
