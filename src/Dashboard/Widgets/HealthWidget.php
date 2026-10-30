<?php

namespace Templite\Cms\Dashboard\Widgets;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Templite\Cms\Contracts\DashboardWidget;
use Templite\Cms\Models\Block;
use Templite\Cms\Models\File;
use Templite\Cms\Models\Manager;
use Templite\Cms\Models\ManagerLog;
use Templite\Cms\Models\ScheduleHistory;

class HealthWidget implements DashboardWidget
{
    public function slug(): string
    {
        return 'cms.health';
    }

    public function label(): string
    {
        return 'Состояние сайта';
    }

    public function description(): string
    {
        return 'Сбои расписания и очередей, неиспользуемые блоки, объём медиатеки и журнала';
    }

    public function permission(): ?string
    {
        return 'settings.view';
    }

    public function view(): string
    {
        return self::VIEW_COUNTERS;
    }

    public function defaultWidth(): int
    {
        return 50;
    }

    public function data(Manager $manager, array $query = []): array
    {
        $settingsUrl = Route::has('cms.core-settings.index') ? route('cms.core-settings.index') : null;

        $failedJobsTable = config('queue.failed.table', 'failed_jobs');

        $items = [
            [
                'label' => 'Сбоев расписания за сутки',
                'value' => ScheduleHistory::where('status', 'fail')->where('ran_at', '>=', now()->subDay())->count(),
                'url' => $settingsUrl,
                'tone' => 'danger',
            ],
            [
                'label' => 'Упавших заданий очереди',
                'value' => Schema::hasTable($failedJobsTable) ? DB::table($failedJobsTable)->count() : 0,
                'url' => $settingsUrl,
                'tone' => 'danger',
            ],
        ];

        if ($manager->hasPermission('blocks.view')) {
            $items[] = [
                'label' => 'Блоков не используется ни на одной странице',
                'value' => Block::doesntHave('pageBlocks')->count(),
                'url' => Route::has('cms.blocks.index') ? route('cms.blocks.index') : null,
                'tone' => 'warning',
            ];
        }

        if ($manager->hasPermission('media.view')) {
            $items[] = [
                'label' => 'Медиатека',
                'value' => $this->size((int) File::sum('size')),
                'url' => Route::has('cms.media.index') ? route('cms.media.index') : null,
                'tone' => 'neutral',
            ];
        }

        if ($manager->hasPermission('logs.view')) {
            $items[] = [
                'label' => 'Записей в журнале',
                'value' => number_format(ManagerLog::count(), 0, '', ' '),
                'url' => Route::has('cms.logs.index') ? route('cms.logs.index') : null,
                'tone' => 'neutral',
            ];
        }

        return ['items' => $items];
    }

    private function size(int $bytes): string
    {
        $units = ['Б', 'КБ', 'МБ', 'ГБ', 'ТБ'];
        $i = 0;

        while ($bytes >= 1024 && $i < count($units) - 1) {
            $bytes /= 1024;
            $i++;
        }

        return round($bytes, 1) . ' ' . $units[$i];
    }
}
