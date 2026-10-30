<?php

namespace Templite\Cms\Dashboard\Widgets;

use Illuminate\Support\Facades\Route;
use Templite\Cms\Contracts\DashboardWidget;
use Templite\Cms\Models\Manager;
use Templite\Cms\Models\ManagerLog;

class SecurityWidget implements DashboardWidget
{
    public function slug(): string
    {
        return 'cms.security';
    }

    public function label(): string
    {
        return 'Безопасность';
    }

    public function description(): string
    {
        return 'Менеджеры без 2FA, заблокированные попытки повышения прав, входы';
    }

    public function permission(): ?string
    {
        return 'managers.view';
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
        $items = [[
            'label' => 'Активных менеджеров без 2FA',
            'value' => Manager::where('is_active', true)->whereNull('two_factor_confirmed_at')->count(),
            'url' => Route::has('cms.managers.index') ? route('cms.managers.index') : null,
            'tone' => 'warning',
        ]];

        if ($manager->hasPermission('logs.view') && Route::has('cms.logs.index')) {
            $items[] = [
                'label' => 'Попыток повышения прав за 30 дней',
                'value' => ManagerLog::where('action', 'permissions_escalation_blocked')
                    ->where('created_at', '>=', now()->subDays(30))->count(),
                'url' => route('cms.logs.index', ['action' => 'permissions_escalation_blocked']),
                'tone' => 'danger',
            ];
            $items[] = [
                'label' => 'Входов за сутки',
                'value' => ManagerLog::whereIn('action', ['login', 'login_with_two_factor', 'two_factor_recovery_used'])
                    ->where('created_at', '>=', now()->subDay())->count(),
                'url' => route('cms.logs.index', ['entity_type' => 'auth']),
                'tone' => 'neutral',
            ];
        }

        return ['items' => $items];
    }
}
