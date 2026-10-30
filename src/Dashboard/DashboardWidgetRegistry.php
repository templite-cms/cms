<?php

namespace Templite\Cms\Dashboard;

use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Facades\Log;
use Templite\Cms\Contracts\DashboardWidget;
use Templite\Cms\Models\Manager;
use Templite\Cms\Services\ModuleRegistry;

class DashboardWidgetRegistry
{
    /** Раскладка, если в типе менеджера она не задана */
    public const DEFAULT_LAYOUT = [
        ['slug' => 'cms.activity', 'width' => 100],
        ['slug' => 'cms.continue', 'width' => 50],
        ['slug' => 'cms.attention', 'width' => 50],
        ['slug' => 'cms.scheduled', 'width' => 100],
    ];

    /** @var array<string,DashboardWidget>|null */
    private ?array $widgets = null;

    public function __construct(
        private readonly ModuleRegistry $modules,
        private readonly Container $app,
    ) {
    }

    /**
     * Модуль отдаёт класс виджета — он мог обновиться без синхронной правки
     * модуля (vendor-пакет отстал) или просто ошибиться в имени класса.
     * Невалидные записи и повтор slug не должны обрушивать весь дашборд.
     *
     * @return array<string,DashboardWidget>
     */
    public function all(): array
    {
        if ($this->widgets === null) {
            $this->widgets = [];

            foreach ($this->modules->getModules() as $module) {
                foreach ($module->getDashboardWidgets() as $class) {
                    if (! is_string($class) || ! is_subclass_of($class, DashboardWidget::class)) {
                        Log::warning(sprintf(
                            "Module '%s' returned an invalid dashboard widget class, ignoring: %s",
                            $module->getName(),
                            is_string($class) ? $class : get_debug_type($class)
                        ));

                        continue;
                    }

                    $widget = $this->app->make($class);
                    $slug = $widget->slug();

                    // Тот же паттерн, что у `where('slug', ...)` маршрута cms.api.dashboard.widget
                    if (! preg_match('/^[a-z0-9.\-]+$/', $slug)) {
                        Log::warning(sprintf(
                            "Module '%s' returned dashboard widget with invalid slug format, ignoring: %s (%s)",
                            $module->getName(),
                            $slug,
                            $class
                        ));

                        continue;
                    }

                    if (isset($this->widgets[$slug])) {
                        Log::warning(sprintf(
                            "Module '%s' returned dashboard widget slug '%s' that is already registered, keeping the first one (%s).",
                            $module->getName(),
                            $slug,
                            $class
                        ));

                        continue;
                    }

                    $this->widgets[$slug] = $widget;
                }
            }
        }

        return $this->widgets;
    }

    public function find(string $slug): ?DashboardWidget
    {
        return $this->all()[$slug] ?? null;
    }

    public function allows(DashboardWidget $widget, Manager $manager): bool
    {
        $permission = $widget->permission();

        return $permission === null || $manager->hasPermission($permission);
    }

    /**
     * Раскладка менеджера: неизвестные (модуль отключён) и недоступные по
     * правам виджеты отбрасываются.
     *
     * @return list<array{widget: DashboardWidget, width: int}>
     */
    public function layoutFor(Manager $manager): array
    {
        $layout = [];

        foreach ($this->rawLayout($manager) as $item) {
            $widget = $this->find($item['slug'] ?? '');

            if ($widget !== null && $this->allows($widget, $manager)) {
                $layout[] = ['widget' => $widget, 'width' => (int) ($item['width'] ?? $widget->defaultWidth())];
            }
        }

        return $layout;
    }

    /** Каталог для редактора раскладки */
    public function catalog(): array
    {
        return array_values(array_map(fn (DashboardWidget $w) => [
            'slug' => $w->slug(),
            'label' => $w->label(),
            'description' => $w->description(),
            'permission' => $w->permission(),
            'default_width' => $w->defaultWidth(),
        ], $this->all()));
    }

    /** @return list<array{slug:string,width:int}> */
    private function rawLayout(Manager $manager): array
    {
        $dashboard = $manager->managerType?->dashboard;

        if (! is_array($dashboard) || $dashboard === []) {
            return self::DEFAULT_LAYOUT;
        }

        // Если модуль со всеми виджетами раскладки отключили — не оставлять
        // дашборд пустым: подсказка редактора обещает раскладку по умолчанию.
        // Права здесь не учитываем — это делает layoutFor() через allows().
        foreach ($dashboard as $item) {
            if ($this->find($item['slug'] ?? '') !== null) {
                return $dashboard;
            }
        }

        return self::DEFAULT_LAYOUT;
    }
}
