<?php

namespace Templite\Cms\Dashboard;

use Illuminate\Database\Eloquent\Builder;
use Templite\Cms\Models\Manager;
use Templite\Cms\Models\ManagerLog;
use Templite\Cms\Services\ActionChannel;

/**
 * Лента журнала для дашборда. Без права logs.view менеджер видит только
 * свои действия — как и страницу журнала.
 */
class ActivityFeed
{
    public const FILTER_ALL = 'all';

    public const FILTER_AI = 'ai';

    public const FILTER_MINE = 'mine';

    public const FILTERS = [self::FILTER_ALL, self::FILTER_AI, self::FILTER_MINE];

    private const PAGE = 20;

    /** Сколько сырых записей читаем за раз: серии склеиваются из них */
    private const BATCH = 200;

    /** Правки одного автора по одной сущности в этом окне — одна строка ленты, секунд */
    private const MERGE_WINDOW = 600;

    /** Входы и 2FA — в виджете безопасности, в ленте они шум */
    private const HIDDEN_TYPES = ['auth'];

    private const ACTIONS = [
        'create' => 'Создание',
        'update' => 'Изменение',
        'delete' => 'Удаление',
        'copy' => 'Копирование',
        'reorder' => 'Сортировка',
        'upload' => 'Загрузка',
        'move' => 'Перемещение',
        'update_code' => 'Изменение кода',
        'action.code.updated' => 'Изменение кода',
        'update_data' => 'Изменение содержимого',
        'save_values' => 'Изменение значений',
        'compile' => 'Пересборка',
        'rebuild' => 'Пересборка',
        'clear_cache' => 'Очистка кэша',
        'invalidate_cache' => 'Сброс кэша',
        'import' => 'Импорт',
        'export' => 'Экспорт',
        'update_status' => 'Смена статуса',
        'permissions_changed' => 'Изменение прав',
        'permissions_set' => 'Назначение прав',
        'permissions_escalation_blocked' => 'Блокировка повышения прав',
        'permissions_self_edit_blocked' => 'Блокировка изменения своих прав',
        'two_factor_reset_by_admin' => 'Сброс 2FA',
        'toggle_active' => 'Переключение активности',
        'grant' => 'Выдача доступа',
        'revoke' => 'Отзыв доступа',
        'reprocess' => 'Переобработка',
        'create_size' => 'Добавление размера',
        'delete_size' => 'Удаление размера',
        'queue.create' => 'Создание очереди',
        'queue.update' => 'Изменение очереди',
        'queue.delete' => 'Удаление из очереди',
        'queue.flush' => 'Очистка очереди',
        'queue.pause' => 'Приостановка очереди',
        'queue.restart' => 'Перезапуск очереди',
        'queue.resume' => 'Возобновление очереди',
        'queue.retry' => 'Повтор задачи',
        'queue.retry_all' => 'Повтор всех задач',
        'schedule.create' => 'Создание задачи',
        'schedule.update' => 'Изменение задачи',
        'schedule.delete' => 'Удаление задачи',
        'schedule.run' => 'Запуск задачи',
    ];

    public function __construct(private readonly EntityLinks $links)
    {
    }

    /**
     * @return array{items: list<array>, next_cursor: ?int, filter: string, scope: string}
     */
    public function page(Manager $viewer, string $filter, ?int $cursor): array
    {
        $canSeeAll = $viewer->hasPermission('logs.view');

        $query = $this->base()->with('manager')->limit(self::BATCH);

        if ($cursor !== null) {
            $query->where('id', '<', $cursor);
        }

        if ($filter === self::FILTER_MINE || ! $canSeeAll) {
            $query->where('manager_id', $viewer->id);
        }

        if ($filter === self::FILTER_AI) {
            $query->whereIn('data->via', [ActionChannel::ASSISTANT, ActionChannel::MCP]);
        }

        $rows = $query->get();
        $targets = $this->links->resolve($rows);
        $groups = [];
        $nextCursor = null;

        foreach ($rows as $row) {
            $item = $this->item($row, $targets[$row->id]);
            $lastKey = array_key_last($groups);

            if ($lastKey !== null && $this->mergeable($groups[$lastKey], $item)) {
                $groups[$lastKey] = $this->merge($groups[$lastKey], $item);

                continue;
            }

            if (count($groups) === self::PAGE) {
                // Следующая страница начнётся с этой записи
                $nextCursor = $row->id + 1;

                break;
            }

            $groups[] = $item;
        }

        if ($nextCursor === null && $rows->count() === self::BATCH) {
            $nextCursor = $rows->last()->id;
        }

        return [
            'items' => array_map(fn (array $g) => array_diff_key($g, array_flip(['_manager', '_key', '_first', '_ts'])), $groups),
            'next_cursor' => $nextCursor,
            'filter' => $filter,
            'scope' => $canSeeAll ? 'all' : 'own',
        ];
    }

    /**
     * Сущности, которые менеджер правил последними (вручную или через ИИ).
     *
     * @return list<array{title:string, subtitle:string, url:string, time:?string}>
     */
    public function recentTargets(Manager $manager, int $limit): array
    {
        $rows = $this->base()->where('manager_id', $manager->id)->limit(self::BATCH)->get();
        $targets = $this->links->resolve($rows);
        $items = [];

        foreach ($rows as $row) {
            $target = $targets[$row->id];

            if (! $target['editable'] || isset($items[$target['key']])) {
                continue;
            }

            $items[$target['key']] = [
                'title' => $target['title'],
                'subtitle' => $target['type'],
                'url' => $target['url'],
                'badge' => null,
                'time' => $row->created_at?->toIso8601String(),
            ];

            if (count($items) === $limit) {
                break;
            }
        }

        return array_values($items);
    }

    /**
     * Последние правки кода блоков, шаблонов, компонентов и actions.
     *
     * @return list<array>
     */
    public function codeChanges(Manager $viewer, int $limit): array
    {
        $query = $this->base()->with('manager')
            ->whereIn('action', ['update_code', 'action.code.updated'])
            ->limit($limit);

        if (! $viewer->hasPermission('logs.view')) {
            $query->where('manager_id', $viewer->id);
        }

        $rows = $query->get();
        $targets = $this->links->resolve($rows);

        return $rows->map(function (ManagerLog $row) use ($targets) {
            $via = $row->data['via'] ?? ActionChannel::ADMIN;

            return [
                'title' => $targets[$row->id]['title'],
                'subtitle' => $targets[$row->id]['type'] . ' · ' . $this->actorName($row),
                'url' => $targets[$row->id]['url'],
                'badge' => self::viaBadge($via),
                'time' => $row->created_at?->toIso8601String(),
            ];
        })->all();
    }

    /** Бейдж канала для list-виджетов; у ручных правок бейджа нет */
    public static function viaBadge(string $via): ?array
    {
        return match ($via) {
            ActionChannel::ASSISTANT => ['text' => 'Ассистент', 'tone' => 'info'],
            ActionChannel::MCP => ['text' => 'MCP', 'tone' => 'primary'],
            default => null,
        };
    }

    private function base(): Builder
    {
        return ManagerLog::query()
            ->whereNotIn('entity_type', self::HIDDEN_TYPES)
            ->orderByDesc('id');
    }

    private function item(ManagerLog $row, array $target): array
    {
        $ts = $row->created_at?->getTimestamp() ?? 0;

        return [
            'id' => $row->id,
            'actor' => [
                'name' => $this->actorName($row),
                'avatar_url' => $row->manager?->avatar_url,
            ],
            'via' => $row->data['via'] ?? ActionChannel::ADMIN,
            // Предложено ассистентом в открытый редактор, сохранено менеджером
            'via_editor' => ! empty($row->data['via_editor']),
            'action' => self::ACTIONS[$row->action] ?? $row->action,
            'entity' => ['type' => $target['type'], 'title' => $target['title'], 'url' => $target['url']],
            'time' => $row->created_at?->toIso8601String(),
            'count' => 1,
            '_manager' => $row->manager_id,
            '_key' => $target['key'],
            '_first' => $ts,
            '_ts' => $ts,
        ];
    }

    /**
     * Окно фиксированное: отсчитывается от первой (самой свежей, записи идут
     * от новых к старым) записи группы, а не скользит с каждой склейкой —
     * иначе цепочка правок одного автора могла растянуться на часы.
     */
    private function mergeable(array $group, array $item): bool
    {
        return $group['_manager'] === $item['_manager']
            && $group['via'] === $item['via']
            && $group['_key'] === $item['_key']
            && $group['_first'] - $item['_ts'] <= self::MERGE_WINDOW;
    }

    private function merge(array $group, array $item): array
    {
        $group['count']++;
        // В серии правок ассистента есть сохранённые человеком из редактора
        $group['via_editor'] = $group['via_editor'] || $item['via_editor'];

        if ($group['action'] !== $item['action']) {
            $group['action'] = 'Изменения';
        }

        return $group;
    }

    private function actorName(ManagerLog $row): string
    {
        return $row->manager?->name ?: ($row->manager?->login ?? 'Менеджер удалён');
    }
}
