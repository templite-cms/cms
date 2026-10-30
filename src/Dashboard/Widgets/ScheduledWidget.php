<?php

namespace Templite\Cms\Dashboard\Widgets;

use Templite\Cms\Contracts\DashboardWidget;
use Templite\Cms\Models\Manager;
use Templite\Cms\Models\Page;

class ScheduledWidget implements DashboardWidget
{
    private const LIMIT = 8;

    public function slug(): string
    {
        return 'cms.scheduled';
    }

    public function label(): string
    {
        return 'Запланировано';
    }

    public function description(): string
    {
        return 'Ближайшие публикации и снятия страниц по расписанию';
    }

    public function permission(): ?string
    {
        return 'pages.view';
    }

    public function view(): string
    {
        return self::VIEW_LIST;
    }

    public function defaultWidth(): int
    {
        return 50;
    }

    public function data(Manager $manager, array $query = []): array
    {
        $events = [];

        foreach (['publish_at' => 'Публикация', 'unpublish_at' => 'Снятие'] as $column => $label) {
            $query = Page::where($column, '>', now());

            // cms:process-scheduled-pages снимает только уже опубликованные страницы (status=1) —
            // у черновика с будущим unpublish_at это событие никогда не произойдёт
            if ($column === 'unpublish_at') {
                $query->where('status', 1);
            }

            $query->orderBy($column)->limit(self::LIMIT)->get(['id', 'title', $column])
                ->each(function (Page $page) use (&$events, $column, $label) {
                    $events[] = [
                        'title' => $page->title,
                        'subtitle' => $label,
                        'url' => route('cms.pages.edit', $page->id),
                        'badge' => null,
                        'date' => $page->{$column}?->toIso8601String(),
                    ];
                });
        }

        usort($events, fn ($a, $b) => strcmp($a['date'], $b['date']));

        return [
            'items' => array_slice($events, 0, self::LIMIT),
            'empty_text' => 'Ничего не запланировано',
        ];
    }
}
