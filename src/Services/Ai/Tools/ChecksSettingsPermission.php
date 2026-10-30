<?php

namespace Templite\Cms\Services\Ai\Tools;

/**
 * Проверка прав на глобальные настройки.
 *
 * Инструменты зовут контроллер через Request::create(), минуя стек маршрута,
 * поэтому навесить проверку на маршрут недостаточно — право проверяется здесь.
 *
 * Причём на сегодня это вообще единственная проверка прав в этой области:
 * группа `/settings/*` в routes/api.php и экраны в routes/admin.php закрыты
 * только аутентификацией, без `can:settings.view` и `can:settings.edit` (в
 * отличие от core-settings и ai-settings, где они стоят). То есть путь через
 * чат строже прямого API — недоработку самих маршрутов это не лечит.
 */
trait ChecksSettingsPermission
{
    /** Идиома из AuthManager: под Sanctum пользователь резолвится через auth(). */
    protected function currentManager(): mixed
    {
        return auth()->user() ?? auth('manager')->user();
    }

    /**
     * @param  string  $permission  settings.view для чтения, settings.edit для записи
     * @return array<string,mixed>|null отказ для агента либо null, если право есть
     */
    protected function denyIfNoSettingsPermission(string $permission): ?array
    {
        $user = $this->currentManager();

        $allowed = $user
            && method_exists($user, 'hasPermission')
            && $user->hasPermission($permission);

        if ($allowed) {
            return null;
        }

        $what = $permission === 'settings.edit'
            ? 'изменение глобальных настроек'
            : 'просмотр глобальных настроек';

        return [
            'error' => 'forbidden',
            'message' => "Нет права {$permission} — {$what} недоступно этому пользователю.",
        ];
    }
}
