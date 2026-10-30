<?php

namespace Templite\Cms\Dashboard\Widgets;

use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\PersonalAccessToken;
use Templite\Cms\Contracts\DashboardWidget;
use Templite\Cms\Models\AiRun;
use Templite\Cms\Models\Manager;
use Templite\Cms\Services\Mcp\McpScope;

class AiUsageWidget implements DashboardWidget
{
    private const DAYS = 30;

    public function slug(): string
    {
        return 'cms.ai-usage';
    }

    public function label(): string
    {
        return 'ИИ и MCP';
    }

    public function description(): string
    {
        return 'Запуски ассистента и расход за 30 дней, активные MCP-токены';
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
        $tokens = 0;
        $cost = 0.0;
        $runs = 0;

        // usage — JSON с разным набором полей у провайдеров: суммируем в PHP,
        // а не SQL-функциями конкретной СУБД
        AiRun::where('created_at', '>=', now()->subDays(self::DAYS))
            ->select(['id', 'usage'])
            ->chunkById(500, function ($chunk) use (&$tokens, &$cost, &$runs) {
                foreach ($chunk as $run) {
                    $runs++;
                    $tokens += (int) ($run->usage['total_tokens'] ?? 0);
                    $cost += (float) ($run->usage['cost'] ?? 0);
                }
            });

        $mcpTokens = PersonalAccessToken::query()
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->get(['id', 'abilities'])
            ->filter(fn (PersonalAccessToken $t) => McpScope::isMcpToken($t))
            ->count();

        $url = Route::has('cms.ai-settings.index') ? route('cms.ai-settings.index') : null;

        return ['items' => [
            ['label' => 'Запусков ассистента', 'value' => $runs, 'url' => $url, 'tone' => 'neutral'],
            ['label' => 'Токенов', 'value' => number_format($tokens, 0, '', ' '), 'url' => $url, 'tone' => 'neutral'],
            ['label' => 'Расход, $', 'value' => number_format($cost, 2, '.', ' '), 'url' => $url, 'tone' => 'neutral'],
            ['label' => 'Активных MCP-токенов', 'value' => $mcpTokens, 'url' => $url, 'tone' => 'neutral'],
        ]];
    }
}
