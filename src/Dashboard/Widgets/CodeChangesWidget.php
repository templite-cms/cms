<?php

namespace Templite\Cms\Dashboard\Widgets;

use Templite\Cms\Contracts\DashboardWidget;
use Templite\Cms\Dashboard\ActivityFeed;
use Templite\Cms\Models\Manager;

class CodeChangesWidget implements DashboardWidget
{
    public function __construct(private readonly ActivityFeed $feed)
    {
    }

    public function slug(): string
    {
        return 'cms.code-changes';
    }

    public function label(): string
    {
        return 'Правки кода';
    }

    public function description(): string
    {
        return 'Последние изменения кода блоков, шаблонов, компонентов и actions';
    }

    public function permission(): ?string
    {
        return 'blocks.code';
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
            'items' => $this->feed->codeChanges($manager, 8),
            'empty_text' => 'Код ещё не меняли',
        ];
    }
}
