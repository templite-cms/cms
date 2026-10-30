<?php

namespace Templite\Cms\Http\Controllers\Admin;

use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Route;
use Templite\Cms\Dashboard\DashboardWidgetRegistry;
use Templite\Cms\Http\CmsResponse;
use Templite\Cms\Models\Manager;

class DashboardController extends Controller
{
    /** Пункты кнопки «Создать»: [право, подпись, маршрут] */
    private const CREATE_MENU = [
        ['pages.create', 'Страницу', 'cms.pages.create'],
        ['blocks.create', 'Блок', 'cms.blocks.create'],
        ['templates.create', 'Шаблон', 'cms.templates.create'],
        ['components.create', 'Компонент', 'cms.components.create'],
    ];

    public function index(DashboardWidgetRegistry $registry): Response
    {
        $manager = auth('manager')->user();
        $widgets = [];

        foreach ($registry->layoutFor($manager) as ['widget' => $widget, 'width' => $width]) {
            $item = [
                'slug' => $widget->slug(),
                'label' => $widget->label(),
                'view' => $widget->view(),
                'width' => $width,
                'data' => null,
                'error' => false,
            ];

            // Сбой одного виджета (например, модульного) не роняет дашборд
            try {
                $item['data'] = $widget->data($manager);
            } catch (\Throwable $e) {
                report($e);
                $item['error'] = true;
            }

            $widgets[] = $item;
        }

        return CmsResponse::page('packages/templite/cms/resources/js/entries/dashboard.js', [
            'widgets' => $widgets,
            'createMenu' => $this->createMenu($manager),
        ], ['title' => 'Дашборд']);
    }

    private function createMenu(Manager $manager): array
    {
        $items = [];

        foreach (self::CREATE_MENU as [$permission, $label, $route]) {
            if ($manager->hasPermission($permission) && Route::has($route)) {
                $items[] = ['label' => $label, 'url' => route($route)];
            }
        }

        return $items;
    }
}
