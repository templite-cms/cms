<?php

namespace Templite\Cms\Dashboard\Widgets;

use Templite\Cms\Contracts\DashboardWidget;
use Templite\Cms\Dashboard\ActivityFeed;
use Templite\Cms\Models\Manager;

class ActivityWidget implements DashboardWidget
{
    public function __construct(private readonly ActivityFeed $feed)
    {
    }

    public function slug(): string
    {
        return 'cms.activity';
    }

    public function label(): string
    {
        return 'Лента активности';
    }

    public function description(): string
    {
        return 'Кто и что менял на сайте: вручную, через ассистента или MCP';
    }

    public function permission(): ?string
    {
        return null;
    }

    public function view(): string
    {
        return self::VIEW_FEED;
    }

    public function defaultWidth(): int
    {
        return 100;
    }

    public function data(Manager $manager, array $query = []): array
    {
        $filter = in_array($query['filter'] ?? null, ActivityFeed::FILTERS, true) ? $query['filter'] : ActivityFeed::FILTER_ALL;
        $cursor = isset($query['cursor']) && is_numeric($query['cursor']) ? (int) $query['cursor'] : null;

        return $this->feed->page($manager, $filter, $cursor);
    }
}
