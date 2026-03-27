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
     * Путь к Vite manifest.json для резолва ассетов модуля.
     * Возвращает абсолютный путь или null если модуль не имеет pre-built assets.
     */
    public function getAssetManifest(): ?string;

    /**
     * Guard'ы пользователей сайта, предоставляемые модулем.
     *
     * @return UserGuardInterface[]
     */
    public function getGuards(): array;
}
