<?php

namespace Templite\Cms\Contracts;

use Templite\Cms\Models\Manager;

/**
 * Виджет дашборда. Данные отдаются в одном из стандартных видов — ядро
 * рисует их своими компонентами, модулю не нужен свой Vue-бандл.
 *
 * counters: ['items' => [['label', 'value', 'url', 'tone' => neutral|warning|danger]]]
 * list:     ['items' => [['title', 'subtitle', 'url', 'badge' => ['text', 'tone' => primary|success|warning|error|info|neutral]|null, 'time'|'date']], 'more_url', 'empty_text']
 * feed:     ['items' => [...], 'next_cursor', 'filter', 'scope' => all|own]
 *
 * Тон badge в list — тот же набор, что классы ядра .badge-primary/.badge-success/
 * .badge-warning/.badge-error/.badge-info/.badge-neutral (см. _badge.scss).
 *
 * Виджет без состояния: реестр создаёт его один раз и держит как singleton, менеджер и параметры запроса приходят только через data().
 */
interface DashboardWidget
{
    public const VIEW_COUNTERS = 'counters';

    public const VIEW_LIST = 'list';

    public const VIEW_FEED = 'feed';

    /**
     * Уникальный ключ с префиксом модуля: cms.activity, shop.products-attention.
     * Формат — только латиница в нижнем регистре, цифры, точка и дефис: /^[a-z0-9.\-]+$/
     * (тот же паттерн, что у маршрута `cms.api.dashboard.widget`).
     */
    public function slug(): string;

    public function label(): string;

    public function description(): string;

    /** Право для показа; null — доступен всем */
    public function permission(): ?string;

    public function view(): string;

    /** 50 или 100 */
    public function defaultWidth(): int;

    /**
     * @param  array<string,mixed>  $query  фильтр и курсор из API виджета
     * @return array<string,mixed>
     */
    public function data(Manager $manager, array $query = []): array;
}
