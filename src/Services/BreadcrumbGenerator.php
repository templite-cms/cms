<?php

namespace Templite\Cms\Services;

use Templite\Cms\Models\Page;

class BreadcrumbGenerator
{
    /**
     * Заголовок страницы в хлебных крошках: bread_title, иначе title.
     */
    protected function resolveTitle(Page $page): string
    {
        return $page->bread_title ?: $page->title;
    }

    /**
     * Загрузить цепочку предков за 1 запрос.
     *
     * @return array<int, Page> Массив от корня к текущей странице (включая саму страницу).
     */
    protected function loadAncestorChain(Page $page): array
    {
        if (!$page->parent_id) {
            return [$page];
        }

        // Один запрос: все страницы с лёгкими колонками, keyBy id
        // На типичном сайте 20-200 страниц — это быстро
        $allPages = Page::select('id', 'parent_id', 'title', 'bread_title', 'url')
            ->get()
            ->keyBy('id');

        // Собираем цепочку от корня к текущей странице
        $chain = [];
        $currentId = $page->parent_id;
        $depth = 0;

        while ($currentId && $allPages->has($currentId) && $depth < 10) {
            $chain[] = $allPages->get($currentId);
            $currentId = $allPages->get($currentId)->parent_id;
            $depth++;
        }

        // chain собран от ближайшего предка к корню — разворачиваем
        $chain = array_reverse($chain);
        // Добавляем текущую страницу в конец
        $chain[] = $page;

        return $chain;
    }

    /**
     * Сгенерировать хлебные крошки для страницы.
     *
     * @return array<int, array{title: string, url: string|null}>
     */
    public function generate(Page $page): array
    {
        $ancestors = $this->loadAncestorChain($page);

        $breadcrumbs = [];
        foreach ($ancestors as $ancestor) {
            $breadcrumbs[] = [
                'title' => $this->resolveTitle($ancestor),
                'url' => $ancestor->url,
            ];
        }

        // Добавляем "Главная" в начало, если её нет
        if (empty($breadcrumbs) || $breadcrumbs[0]['url'] !== '/') {
            array_unshift($breadcrumbs, [
                'title' => 'Главная',
                'url' => '/',
            ]);
        }

        // Последний элемент без ссылки (текущая страница)
        if (!empty($breadcrumbs)) {
            $lastIndex = count($breadcrumbs) - 1;
            $breadcrumbs[$lastIndex]['url'] = null;
        }

        return $breadcrumbs;
    }

    /**
     * Сгенерировать JSON-LD для хлебных крошек (Schema.org BreadcrumbList).
     */
    public function generateJsonLd(Page $page): array
    {
        $breadcrumbs = $this->generate($page);

        $items = [];
        foreach ($breadcrumbs as $index => $crumb) {
            $item = [
                '@type' => 'ListItem',
                'position' => $index + 1,
                'name' => $crumb['title'],
            ];

            if ($crumb['url']) {
                $item['item'] = url($crumb['url']);
            }

            $items[] = $item;
        }

        return [
            '@context' => 'https://schema.org',
            '@type' => 'BreadcrumbList',
            'itemListElement' => $items,
        ];
    }
}
