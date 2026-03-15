<?php

namespace Templite\Cms\Services;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Templite\Cms\Contracts\TempliteModuleInterface;

class ModuleRegistry
{
    private ?array $cachedModules = null;

    public function __construct(private Application $app)
    {
    }

    /**
     * Получить все зарегистрированные модули.
     *
     * @return TempliteModuleInterface[]
     */
    public function getModules(): array
    {
        if ($this->cachedModules === null) {
            try {
                $this->cachedModules = iterator_to_array($this->app->tagged('cms.modules'));
            } catch (\ReflectionException) {
                $this->cachedModules = [];
            }
        }

        return $this->cachedModules;
    }

    /**
     * Получить навигацию из всех модулей (с резолвом роутов и фильтрацией по правам).
     */
    public function getNavigation(?object $user = null): array
    {
        $groups = [];

        foreach ($this->getModules() as $module) {
            foreach ($module->getNavigation() as $group) {
                $groups[] = $group;
            }
        }

        // Добавляем settings из модулей как отдельные пункты в группу "Настройки"
        $settingsItems = $this->getSettings();
        if (!empty($settingsItems)) {
            // Ищем существующую группу "settings"
            $found = false;
            foreach ($groups as &$group) {
                if (($group['key'] ?? '') === 'settings') {
                    $group['items'] = array_merge($group['items'], $settingsItems);
                    $found = true;
                    break;
                }
            }
            unset($group);

            if (!$found) {
                $groups[] = [
                    'key' => 'settings',
                    'label' => 'Настройки',
                    'position' => 100,
                    'items' => $settingsItems,
                ];
            }
        }

        $groups = $this->resolveGroupRoutes($groups);
        $groups = $this->sortGroups($groups);

        if ($user) {
            $groups = $this->filterByPermissions($groups, $user);
        }

        return $groups;
    }

    /**
     * Получить все permissions из всех модулей.
     */
    public function getPermissions(): array
    {
        $permissions = [];
        $seen = [];

        foreach ($this->getModules() as $module) {
            foreach ($module->getPermissions() as $key => $label) {
                if (isset($seen[$key])) {
                    Log::warning("Duplicate permission key '{$key}' from module '{$module->getName()}', ignoring.");
                    continue;
                }
                $seen[$key] = true;
                $permissions[$key] = $label;
            }
        }

        return $permissions;
    }

    /**
     * Получить все permission ключи (плоский массив для ManagerType).
     */
    public function getPermissionKeys(): array
    {
        return array_keys($this->getPermissions());
    }

    /**
     * Получить permissions сгруппированные по модулю.
     */
    public function getPermissionsGrouped(): array
    {
        $grouped = [];

        foreach ($this->getModules() as $module) {
            $perms = $module->getPermissions();
            if (!empty($perms)) {
                $grouped[] = [
                    'module' => $module->getName(),
                    'label' => $module->getLabel(),
                    'permissions' => $perms,
                ];
            }
        }

        return $grouped;
    }

    /**
     * Получить виджеты дашборда из всех модулей.
     */
    public function getDashboardWidgets(): array
    {
        $widgets = [];

        foreach ($this->getModules() as $module) {
            foreach ($module->getDashboardWidgets() as $widget) {
                // Резолвим data_route в data_url
                if (isset($widget['data_route']) && Route::has($widget['data_route'])) {
                    $widget['data_url'] = route($widget['data_route']);
                    unset($widget['data_route']);
                }
                $widgets[] = $widget;
            }
        }

        usort($widgets, fn ($a, $b) => ($a['position'] ?? 0) <=> ($b['position'] ?? 0));

        return $widgets;
    }

    /**
     * Получить настройки из всех модулей.
     */
    public function getSettings(): array
    {
        $settings = [];

        foreach ($this->getModules() as $module) {
            foreach ($module->getSettings() as $item) {
                $settings[] = $item;
            }
        }

        usort($settings, fn ($a, $b) => ($a['position'] ?? 0) <=> ($b['position'] ?? 0));

        return $settings;
    }

    /**
     * Получить все JS-скрипты модулей.
     */
    public function getScripts(): array
    {
        $scripts = [];

        foreach ($this->getModules() as $module) {
            foreach ($module->getScripts() as $script) {
                $scripts[] = $script;
            }
        }

        return $scripts;
    }

    /**
     * Получить все CSS-стили модулей.
     */
    public function getStyles(): array
    {
        $styles = [];

        foreach ($this->getModules() as $module) {
            foreach ($module->getStyles() as $style) {
                $styles[] = $style;
            }
        }

        return $styles;
    }

    /**
     * Получить информацию обо всех модулях (для отображения в админке).
     */
    public function getModuleInfo(): array
    {
        $info = [];

        foreach ($this->getModules() as $module) {
            $info[] = [
                'name' => $module->getName(),
                'label' => $module->getLabel(),
                'version' => $module->getVersion(),
            ];
        }

        return $info;
    }

    /**
     * Резолвить именованные роуты в href для навигационных групп.
     * Пункты с несуществующими роутами отфильтровываются.
     */
    private function resolveGroupRoutes(array $groups): array
    {
        foreach ($groups as &$group) {
            if (isset($group['route'])) {
                if (Route::has($group['route'])) {
                    $group['href'] = route($group['route']);
                } else {
                    Log::debug("Navigation route '{$group['route']}' not found, skipping group.");
                    $group = null;
                    continue;
                }
                unset($group['route']);
            }

            if (isset($group['items'])) {
                $resolvedItems = [];
                foreach ($group['items'] as $item) {
                    if (isset($item['route'])) {
                        if (Route::has($item['route'])) {
                            $item['href'] = route($item['route']);
                            unset($item['route']);
                            $resolvedItems[] = $item;
                        } else {
                            Log::debug("Navigation route '{$item['route']}' not found, skipping item.");
                        }
                    } else {
                        $resolvedItems[] = $item;
                    }
                }
                $group['items'] = $resolvedItems;

                // Убираем группы без элементов
                if (empty($group['items']) && !isset($group['href'])) {
                    $group = null;
                    continue;
                }
            }
        }
        unset($group);

        return array_values(array_filter($groups));
    }

    /**
     * Сортировка групп и их элементов по position.
     */
    private function sortGroups(array $groups): array
    {
        usort($groups, function ($a, $b) {
            $posCompare = ($a['position'] ?? 0) <=> ($b['position'] ?? 0);
            if ($posCompare !== 0) return $posCompare;
            return strcmp($a['label'] ?? '', $b['label'] ?? '');
        });

        foreach ($groups as &$group) {
            if (isset($group['items'])) {
                usort($group['items'], function ($a, $b) {
                    $posCompare = ($a['position'] ?? 0) <=> ($b['position'] ?? 0);
                    if ($posCompare !== 0) return $posCompare;
                    return strcmp($a['label'] ?? '', $b['label'] ?? '');
                });
            }
        }
        unset($group);

        return $groups;
    }

    /**
     * Фильтрация навигации по правам пользователя.
     */
    private function filterByPermissions(array $groups, object $user): array
    {
        $filtered = [];

        foreach ($groups as $group) {
            if (isset($group['permission']) && !$this->userCan($user, $group['permission'])) {
                continue;
            }

            if (isset($group['items'])) {
                $group['items'] = array_values(array_filter(
                    $group['items'],
                    fn ($item) => !isset($item['permission']) || $this->userCan($user, $item['permission'])
                ));

                if (empty($group['items'])) {
                    continue;
                }
            }

            $filtered[] = $group;
        }

        return $filtered;
    }

    /**
     * Проверить, есть ли у пользователя право.
     */
    private function userCan(object $user, string $permission): bool
    {
        if (method_exists($user, 'hasPermission')) {
            return $user->hasPermission($permission);
        }

        return true;
    }
}
