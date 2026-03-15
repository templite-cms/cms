<?php

namespace Templite\Cms\Contracts;

interface TempliteModuleInterface
{
    /**
     * Уникальное имя модуля (slug): 'cms', 'crm', 'shop'.
     */
    public function getName(): string;

    /**
     * Человекочитаемое название: 'CMS', 'CRM', 'Магазин'.
     */
    public function getLabel(): string;

    /**
     * Версия модуля: '1.0.0'.
     */
    public function getVersion(): string;

    /**
     * Пункты навигации. Формат:
     * [
     *   [
     *     'key' => 'unique-key',
     *     'label' => 'Название группы',
     *     'position' => 10,
     *     'items' => [
     *       ['label' => 'Пункт', 'route' => 'cms.route.name', 'icon' => 'icon-name', 'permission' => 'module.entity.action', 'position' => 10],
     *     ],
     *   ],
     * ]
     */
    public function getNavigation(): array;

    /**
     * Права доступа. Формат: ['module.entity.action' => 'Описание']
     */
    public function getPermissions(): array;

    /**
     * Виджеты дашборда. Формат:
     * [
     *   [
     *     'slug' => 'unique-slug',
     *     'label' => 'Название',
     *     'component' => 'VueComponentName',
     *     'size' => 'sm|md|lg',
     *     'position' => 10,
     *     'data_route' => 'api.route.name', // optional
     *   ],
     * ]
     */
    public function getDashboardWidgets(): array;

    /**
     * Страницы настроек модуля для навигации. Формат:
     * [
     *   ['label' => 'Название', 'route' => 'cms.module.settings.index', 'icon' => 'icon', 'position' => 10],
     * ]
     */
    public function getSettings(): array;

    /**
     * JS-файлы модуля (IIFE-бандлы). Пути относительно public/.
     * Пример: ['vendor/crm/js/crm-pages.iife.js']
     */
    public function getScripts(): array;

    /**
     * CSS-файлы модуля. Пути относительно public/.
     * Пример: ['vendor/crm/css/crm.css']
     */
    public function getStyles(): array;
}
