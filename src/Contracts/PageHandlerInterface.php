<?php

namespace Templite\Cms\Contracts;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Templite\Cms\Models\Page;

interface PageHandlerInterface
{
    /**
     * Обработать запрос к handler-странице.
     *
     * @param Page $mountPage Страница-точка монтирования
     * @param string $path Часть URL после slug страницы (может быть пустой)
     * @param Request $request HTTP-запрос
     * @return Response
     */
    public function handle(Page $mountPage, string $path, Request $request): Response;

    /**
     * Человекочитаемое название handler'а (для UI в админке).
     */
    public function getLabel(): string;

    /**
     * Vue-компонент настроек handler'а (nullable).
     * Используется в CMS-админке для отображения специфичных настроек.
     */
    public function getSettingsComponent(): ?string;

    /**
     * Получить URL'ы для sitemap.
     *
     * @param Page $mountPage Страница-точка монтирования
     * @return array<array{url: string, lastmod?: string, changefreq?: string, priority?: float}>
     */
    public function getSitemapUrls(Page $mountPage): array;
}
