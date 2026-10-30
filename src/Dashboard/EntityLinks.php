<?php

namespace Templite\Cms\Dashboard;

use Illuminate\Support\Facades\Route;
use Templite\Cms\Models\Action;
use Templite\Cms\Models\Block;
use Templite\Cms\Models\BlockPreset;
use Templite\Cms\Models\BlockType;
use Templite\Cms\Models\Component;
use Templite\Cms\Models\ManagerLog;
use Templite\Cms\Models\Page;
use Templite\Cms\Models\PageType;
use Templite\Cms\Models\TemplatePage;

/**
 * Куда ведёт запись журнала: подпись типа, актуальное название и ссылка в
 * админке. У удалённой сущности — название из журнала и без ссылки.
 */
class EntityLinks
{
    /** Сущности со своей страницей: тип => [подпись, маршрут, модель, колонка названия] */
    private const EDITABLE = [
        'page' => ['Страница', 'cms.pages.edit', Page::class, 'title'],
        'block' => ['Блок', 'cms.blocks.edit', Block::class, 'name'],
        'template' => ['Шаблон', 'cms.templates.edit', TemplatePage::class, 'name'],
        'component' => ['Компонент', 'cms.components.edit', Component::class, 'name'],
        'action' => ['Action', 'cms.actions.edit', Action::class, 'name'],
        'block_type' => ['Тип блока', 'cms.block-types.edit', BlockType::class, 'name'],
        'page_type' => ['Тип страницы', 'cms.page-types.edit', PageType::class, 'name'],
        'block_preset' => ['Пресет', 'cms.presets.edit', BlockPreset::class, 'name'],
    ];

    /** Сущности без своей страницы — ссылка на раздел: тип => [подпись, маршрут] */
    private const SECTIONS = [
        'global_settings' => ['Настройки сайта', 'cms.settings.index'],
        'core_settings' => ['Настройки ядра', 'cms.core-settings.index'],
        'cache' => ['Кэш', 'cms.core-settings.index'],
        'file' => ['Файл', 'cms.media.index'],
        'file_folder' => ['Папка', 'cms.media.index'],
        'library' => ['Библиотека', 'cms.libraries.index'],
        'manager' => ['Менеджер', 'cms.managers.index'],
        'manager_type' => ['Тип менеджера', 'cms.managers.index'],
        'mcp_token' => ['MCP-токен', 'cms.profile.index'],
        'api_token' => ['API-токен', 'cms.profile.index'],
        // Дочерние записи без id родителя в data (старые записи либо владелец
        // поля — не блок, см. PARENTS и BlockFieldController::ownerLogData).
        'page_block' => ['Блок страницы', 'cms.pages.index'],
        'block_field' => ['Поле блока', 'cms.blocks.index'],
        'block_action' => ['Action блока', 'cms.blocks.index'],
        'template_field' => ['Поле шаблона', 'cms.templates.index'],
        'page_type_attribute' => ['Атрибут типа страницы', 'cms.page-types.index'],
        'queue' => ['Очередь', 'cms.core-settings.index'],
        'failed_job' => ['Задание очереди', 'cms.core-settings.index'],
        'failed_jobs' => ['Задания очереди', 'cms.core-settings.index'],
        'scheduled_task' => ['Задача расписания', 'cms.core-settings.index'],
        'schedule_task' => ['Задача расписания', 'cms.core-settings.index'],
        'export_import' => ['Экспорт и импорт', 'cms.export-import.index'],
        'ai_key' => ['Ключ ИИ', 'cms.ai-settings.index'],
        'ai_settings' => ['Настройки ИИ', 'cms.ai-settings.index'],
    ];

    /** Дочерние записи показываются на родителе: тип => [тип родителя, ключ id в data] */
    private const PARENTS = [
        'page_block' => ['page', 'page_id'],
        'block_action' => ['block', 'block_id'],
        'block_field' => ['block', 'block_id'],
        'template_field' => ['template', 'template_id'],
    ];

    /**
     * @param  iterable<ManagerLog>  $logs
     * @return array<int, array{key:string, type:string, title:string, url:?string, editable:bool}> по id записи
     */
    public function resolve(iterable $logs): array
    {
        $targets = [];

        foreach ($logs as $log) {
            $targets[$log->id] = $this->target($log);
        }

        $titles = $this->liveTitles($targets);
        $result = [];

        foreach ($targets as $logId => [$type, $id, $fallback]) {
            $result[$logId] = $this->present($type, $id, $fallback, $titles);
        }

        return $result;
    }

    /** @return array{0:string, 1:?int, 2:?string} тип, id, название из журнала */
    private function target(ManagerLog $log): array
    {
        $data = $log->data ?? [];

        if (isset(self::PARENTS[$log->entity_type])) {
            [$parentType, $key] = self::PARENTS[$log->entity_type];

            if (isset($data[$key])) {
                $parentFallback = $data['title'] ?? $data['name'] ?? null;

                return [$parentType, (int) $data[$key], is_string($parentFallback) ? $parentFallback : null];
            }
        }

        $fallback = $data['title'] ?? $data['name'] ?? $data['login'] ?? $data['path'] ?? $data['key'] ?? null;

        return [$log->entity_type, $log->entity_id, is_string($fallback) ? $fallback : null];
    }

    /** @return array<string, array<int,string>> названия только существующих сущностей */
    private function liveTitles(array $targets): array
    {
        $ids = [];

        foreach ($targets as [$type, $id]) {
            if ($id !== null && isset(self::EDITABLE[$type])) {
                $ids[$type][$id] = $id;
            }
        }

        $titles = [];

        foreach ($ids as $type => $list) {
            [, , $model, $column] = self::EDITABLE[$type];
            $titles[$type] = $model::whereIn('id', array_values($list))->pluck($column, 'id')->all();
        }

        return $titles;
    }

    private function present(string $type, ?int $id, ?string $fallback, array $titles): array
    {
        $key = $type . ':' . ($id ?? '-');

        if (isset(self::EDITABLE[$type])) {
            [$label, $route] = self::EDITABLE[$type];
            $live = $id !== null ? ($titles[$type][$id] ?? null) : null;

            return [
                'key' => $key,
                'type' => $label,
                'title' => $live ?? $fallback ?? ($id !== null ? "#{$id}" : ''),
                'url' => $live !== null && Route::has($route) ? route($route, $id) : null,
                'editable' => $live !== null,
            ];
        }

        [$label, $route] = self::SECTIONS[$type] ?? [$type, null];

        return [
            'key' => $key,
            'type' => $label,
            'title' => $fallback ?? '',
            'url' => $route !== null && Route::has($route) ? route($route) : null,
            'editable' => false,
        ];
    }
}
