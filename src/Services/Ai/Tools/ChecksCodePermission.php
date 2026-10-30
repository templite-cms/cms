<?php

namespace Templite\Cms\Services\Ai\Tools;

/**
 * Проверка права на изменение кода блоков и actions.
 *
 * Инструменты вызывают контроллеры напрямую, минуя HTTP-стек, поэтому
 * middleware `can:blocks.code` и `can:actions.code` не отрабатывают — иначе
 * чат стал бы обходом ограничения доступа к коду.
 */
trait ChecksCodePermission
{
    /**
     * Текущий менеджер. Идиома из AuthManager: дефолтный guard — web,
     * поэтому под Sanctum пользователь резолвится через auth(), а в остальных
     * случаях — через guard manager.
     */
    protected function currentManager(): mixed
    {
        return auth()->user() ?? auth('manager')->user();
    }

    /**
     * Превью с черновиком рендерит присланный Blade — это исполнение кода,
     * а не чтение: без права на код черновик не принимаем. Превью
     * сохранённого кода (без template/style/script) остаётся доступным.
     *
     * @param  array<string,mixed>  $args
     * @return array<string,mixed>|null
     */
    protected function denyDraftWithoutCodePermission(array $args, string $permission): ?array
    {
        $hasDraft = isset($args['template']) || isset($args['style']) || isset($args['script']);

        return $hasDraft ? $this->denyIfNoCodePermission($permission) : null;
    }

    /**
     * @param  string  $permission  право, закрывающее соответствующий эндпоинт:
     *                              blocks.code — код блоков, actions.code — код actions
     * @return array<string,mixed>|null отказ для агента либо null, если право есть
     */
    protected function denyIfNoCodePermission(string $permission = 'blocks.code'): ?array
    {
        $user = $this->currentManager();

        $allowed = $user
            && method_exists($user, 'hasPermission')
            && $user->hasPermission($permission);

        if ($allowed) {
            return null;
        }

        $what = match ($permission) {
            'actions.code' => 'изменение кода actions',
            'templates.code' => 'изменение кода шаблонов',
            'components.code' => 'изменение кода компонентов',
            default => 'изменение кода блоков',
        };

        return [
            'error' => 'forbidden',
            'message' => "Нет права {$permission} — {$what} недоступно этому пользователю.",
        ];
    }
}
