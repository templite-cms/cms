<?php

namespace Templite\Cms\Dashboard\Widgets;

use Templite\Cms\Contracts\DashboardWidget;
use Templite\Cms\Dashboard\ActivityFeed;
use Templite\Cms\Models\Manager;

class ContinueWidget implements DashboardWidget
{
    public function __construct(private readonly ActivityFeed $feed)
    {
    }

    public function slug(): string
    {
        return 'cms.continue';
    }

    public function label(): string
    {
        return 'Продолжить работу';
    }

    public function description(): string
    {
        return 'Что вы недавно меняли: страницы, блоки, шаблоны и другое';
    }

    public function permission(): ?string
    {
        return null;
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
        return [
            'items' => $this->feed->recentTargets($manager, 8),
            'empty_text' => 'Вы ещё ничего не меняли',
        ];
    }
}
