<?php

namespace Templite\Cms\Modules;

use Templite\Cms\Contracts\AbstractModule;

class CmsModule extends AbstractModule
{
    public function getName(): string
    {
        return 'cms';
    }

    public function getLabel(): string
    {
        return 'CMS';
    }

    public function getNavigation(): array
    {
        return [
            [
                'key' => 'site',
                'label' => 'Сайт',
                'position' => 10,
                'items' => [
                    ['label' => 'Страницы', 'route' => 'cms.pages.index', 'icon' => 'file-text', 'permission' => 'pages.view', 'position' => 10],
                    ['label' => 'Глобальные настройки', 'route' => 'cms.settings.index', 'icon' => 'settings', 'permission' => 'settings.view', 'position' => 20],
                    ['label' => 'Медиа', 'route' => 'cms.media.index', 'icon' => 'image', 'permission' => 'media.view', 'position' => 30],
                ],
            ],
            [
                'key' => 'constructor',
                'label' => 'Конструктор',
                'position' => 20,
                'items' => [
                    ['label' => 'Блоки', 'route' => 'cms.blocks.index', 'icon' => 'boxes', 'permission' => 'blocks.view', 'position' => 10],
                    ['label' => 'Пресеты', 'route' => 'cms.presets.index', 'icon' => 'sparkles', 'permission' => 'blocks.view', 'position' => 20],
                    ['label' => 'Действия', 'route' => 'cms.actions.index', 'icon' => 'zap', 'permission' => 'actions.view', 'position' => 30],
                    ['label' => 'Компоненты', 'route' => 'cms.components.index', 'icon' => 'component', 'permission' => 'components.view', 'position' => 40],
                    ['label' => 'Шаблоны', 'route' => 'cms.templates.index', 'icon' => 'layout', 'permission' => 'templates.view', 'position' => 50],
                    ['label' => 'Структура настроек', 'route' => 'cms.settings.structure', 'icon' => 'list-tree', 'permission' => 'settings.view', 'position' => 60],
                    ['label' => 'Библиотеки', 'route' => 'cms.libraries.index', 'icon' => 'library', 'permission' => 'blocks.view', 'position' => 70],
                    ['label' => 'Типы страниц', 'route' => 'cms.page-types.index', 'icon' => 'layers', 'permission' => 'page_types.view', 'position' => 80],
                    ['label' => 'Файлы', 'route' => 'cms.file-manager.index', 'icon' => 'folder-open', 'permission' => 'file_manager.view', 'position' => 90],
                ],
            ],
            [
                'key' => 'settings',
                'label' => 'Настройки',
                'position' => 90,
                'items' => [
                    ['label' => 'Ядро', 'route' => 'cms.core-settings.index', 'icon' => 'cpu', 'permission' => 'settings.view', 'position' => 10],
                    ['label' => 'AI-ассистент', 'route' => 'cms.ai-settings.index', 'icon' => 'bot', 'permission' => 'ai.use', 'position' => 15],
                    ['label' => 'Менеджеры', 'route' => 'cms.managers.index', 'icon' => 'users', 'permission' => 'managers.view', 'position' => 20],
                    ['label' => 'Импорт / Экспорт', 'route' => 'cms.export-import.index', 'icon' => 'arrow-left-right', 'permission' => 'settings.edit', 'position' => 30],
                    ['label' => 'Логи', 'route' => 'cms.logs.index', 'icon' => 'scroll-text', 'permission' => 'logs.view', 'position' => 40],
                ],
            ],
        ];
    }

    public function getPermissions(): array
    {
        return [
            // Контент
            'pages.view' => 'Просмотр страниц',
            'pages.create' => 'Создание страниц',
            'pages.edit' => 'Редактирование страниц',
            'pages.delete' => 'Удаление страниц',
            'page_types.view' => 'Просмотр типов страниц',
            'page_types.create' => 'Создание типов страниц',
            'page_types.edit' => 'Редактирование типов страниц',
            'page_types.delete' => 'Удаление типов страниц',
            // Конструктор
            'blocks.view' => 'Просмотр блоков',
            'blocks.create' => 'Создание блоков',
            'blocks.edit' => 'Редактирование блоков',
            'blocks.delete' => 'Удаление блоков',
            'blocks.code' => 'Код блоков (Blade, SCSS, JS) — исполнение кода на сервере',
            'block_types.view' => 'Просмотр типов блоков',
            'block_types.create' => 'Создание типов блоков',
            'block_types.edit' => 'Редактирование типов блоков',
            'block_types.delete' => 'Удаление типов блоков',
            'actions.view' => 'Просмотр действий',
            'actions.create' => 'Создание действий',
            'actions.edit' => 'Редактирование действий',
            'actions.delete' => 'Удаление действий',
            'actions.code' => 'Код действий (PHP) — исполнение кода на сервере',
            'components.view' => 'Просмотр компонентов',
            'components.create' => 'Создание компонентов',
            'components.edit' => 'Редактирование компонентов',
            'components.delete' => 'Удаление компонентов',
            'components.code' => 'Код компонентов (Blade, SCSS, JS) — исполнение кода на сервере',
            'templates.view' => 'Просмотр шаблонов',
            'templates.create' => 'Создание шаблонов',
            'templates.edit' => 'Редактирование шаблонов',
            'templates.delete' => 'Удаление шаблонов',
            'templates.code' => 'Код шаблонов (Blade, SCSS, JS) — исполнение кода на сервере',
            // Медиа
            'media.view' => 'Просмотр медиа',
            'media.upload' => 'Загрузка медиа',
            'media.edit' => 'Редактирование медиа',
            'media.delete' => 'Удаление медиа',
            'file_manager.view' => 'Просмотр файлов',
            'file_manager.edit' => 'Редактирование файлов',
            // Настройки
            'settings.view' => 'Просмотр настроек',
            'settings.edit' => 'Редактирование настроек',
            'ai.use' => 'Использование AI-ассистента',
            'ai.keys.manage' => 'Управление своими AI-ключами',
            'mcp.use' => 'Доступ через MCP (внешние AI-клиенты)',
            'managers.view' => 'Просмотр менеджеров',
            'managers.create' => 'Создание менеджеров',
            'managers.edit' => 'Редактирование менеджеров',
            'managers.delete' => 'Удаление менеджеров',
            'manager_types.view' => 'Просмотр типов менеджеров',
            'manager_types.create' => 'Создание типов менеджеров',
            'manager_types.edit' => 'Редактирование типов менеджеров',
            'manager_types.delete' => 'Удаление типов менеджеров',
            'logs.view' => 'Просмотр логов',
        ];
    }

    public function getDashboardWidgets(): array
    {
        return [
            \Templite\Cms\Dashboard\Widgets\ActivityWidget::class,
            \Templite\Cms\Dashboard\Widgets\ContinueWidget::class,
            \Templite\Cms\Dashboard\Widgets\AttentionWidget::class,
            \Templite\Cms\Dashboard\Widgets\ScheduledWidget::class,
            \Templite\Cms\Dashboard\Widgets\CodeChangesWidget::class,
            \Templite\Cms\Dashboard\Widgets\HealthWidget::class,
            \Templite\Cms\Dashboard\Widgets\SecurityWidget::class,
            \Templite\Cms\Dashboard\Widgets\AiUsageWidget::class,
        ];
    }

    public function getAssetManifest(): ?string
    {
        return public_path('vendor/cms/build/manifest.json');
    }
}
