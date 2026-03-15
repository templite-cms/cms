<?php

namespace Templite\Cms\Contracts;

/**
 * Определяет контракт для Actions -- модулей бизнес-логики, привязываемых к блокам.
 * Action получает данные (параметры) и возвращает данные для использования
 * в Blade-шаблоне блока.
 */
interface BlockActionInterface
{
    /**
     * Определение входных параметров.
     *
     * Используется для:
     * - Генерации UI формы настройки в админке
     * - Валидации параметров при привязке к блоку
     * - Описания в MCP-протоколе
     *
     * Формат:
     * [
     *     'page_type' => [
     *         'type' => 'select',
     *         'label' => 'Тип страницы',
     *         'options' => 'page_types',
     *         'required' => true,
     *     ],
     *     'limit' => [
     *         'type' => 'number',
     *         'label' => 'Количество',
     *         'default' => 6,
     *     ],
     * ]
     *
     * @return array<string, array>
     */
    public function params(): array;

    /**
     * Описание возвращаемых данных.
     *
     * Используется для:
     * - Подсказок доступных переменных в редакторе кода блока
     * - Документации в MCP-протоколе
     * - Автодополнения в CodeMirror
     *
     * Формат:
     * [
     *     'pages' => [
     *         'type' => 'Collection<Page>',
     *         'description' => 'Коллекция страниц',
     *     ],
     *     'total' => [
     *         'type' => 'int',
     *         'description' => 'Общее количество',
     *     ],
     * ]
     *
     * @return array<string, array>
     */
    public function returns(): array;

    /**
     * Выполнение action.
     *
     * @param array $params   Параметры, определённые в params() и настроенные при привязке к блоку
     * @param ActionContext $context Контекст: текущая страница, запрос, глобальные поля, данные блока
     * @return array Данные, доступные в Blade-шаблоне блока как переменные
     */
    public function handle(array $params, ActionContext $context): array;
}
