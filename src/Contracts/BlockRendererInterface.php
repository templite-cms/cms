<?php

namespace Templite\Cms\Contracts;

use Templite\Cms\Models\Block;
use Templite\Cms\Models\Page;

/**
 * Определяет контракт рендеринга блока.
 * Позволяет заменить стандартный Blade-рендерер на кастомный
 * (например, для SSR или edge-рендеринга).
 */
interface BlockRendererInterface
{
    /**
     * Рендерит блок и возвращает HTML-строку.
     *
     * В Blade-шаблоне доступны 5 переменных:
     * - $fields  — Resolved-значения полей блока (ассоц. массив key => value)
     * - $actions — Данные actions, сгруппированные по slug: [action_slug => [...]]
     * - $page    — Текущая страница (null в превью редактора блока)
     * - $global  — Глобальные поля сайта ([] если недоступны)
     * - $block   — Объект Block
     *
     * @param Block  $block   Определение блока (метаданные, поля)
     * @param array  $fields  Resolved-значения полей блока
     * @param array  $actions Данные actions, сгруппированные по slug
     * @param ?Page  $page    Текущая страница (null в превью редактора)
     * @param array  $global  Глобальные поля
     * @return string         HTML-строка результата рендеринга
     */
    public function render(
        Block $block,
        array $fields,
        array $actions,
        ?Page $page,
        array $global,
    ): string;

    /**
     * Компилирует SCSS блока в CSS (если есть style.scss).
     *
     * @param Block $block
     * @return string|null CSS-строка или null если нет стилей
     */
    public function compileStyles(Block $block): ?string;

    /**
     * Возвращает путь к директории файлов блока.
     * Учитывает принцип трёх источников.
     *
     * @param Block $block
     * @return string|null Абсолютный путь к директории или null
     */
    public function resolveBlockPath(Block $block): ?string;
}
