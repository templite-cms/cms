<?php

namespace Templite\Cms\Services;

use Illuminate\Support\Collection;
use Templite\Cms\Models\City;
use Templite\Cms\Models\Page;
use Templite\Cms\Models\PageTranslation;

class BreadcrumbGenerator
{
    /**
     * Получить контекст текущего языка из контейнера.
     *
     * @return array{currentLang: string|null, isDefaultLang: bool, langPrefix: string}
     */
    protected function getLangContext(): array
    {
        $currentLang = app()->bound('current_language') ? app('current_language') : null;
        $isDefaultLang = app()->bound('is_default_language') ? app('is_default_language') : true;
        $langPrefix = (!$isDefaultLang && $currentLang) ? '/' . $currentLang : '';

        return compact('currentLang', 'isDefaultLang', 'langPrefix');
    }

    /**
     * Получить заголовок страницы с учётом перевода.
     *
     * Если переводы предзагружены через setRelation('translations', ...),
     * используется коллекция без дополнительного запроса.
     */
    protected function resolveTitle(Page $page, ?string $currentLang, bool $isDefaultLang): string
    {
        $title = $page->bread_title ?: $page->title;

        if ($currentLang && !$isDefaultLang) {
            // Используем предзагруженные переводы если есть
            $translation = $page->relationLoaded('translations')
                ? $page->translations->firstWhere('lang', $currentLang)
                : $page->translation($currentLang);

            if ($translation) {
                $title = $translation->bread_title ?: $translation->title ?: $title;
            }
        }

        return $title;
    }

    /**
     * Загрузить цепочку предков за 1 запрос + пакетно загрузить переводы.
     *
     * @return array<int, Page> Массив от корня к текущей странице (включая саму страницу).
     */
    protected function loadAncestorChain(Page $page, ?string $lang = null): array
    {
        if (!$page->parent_id) {
            return [$page];
        }

        // Один запрос: все страницы с лёгкими колонками, keyBy id
        // На типичном сайте 20-200 страниц — это быстро
        $allPages = Page::select('id', 'parent_id', 'title', 'bread_title', 'url', 'city_scope')
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

        // Пакетно загружаем переводы для всех предков (кроме текущей страницы, у которой переводы могут быть уже)
        if ($lang) {
            $ancestorIds = array_map(fn (Page $p) => $p->id, $chain);
            $translations = PageTranslation::whereIn('page_id', $ancestorIds)
                ->where('lang', $lang)
                ->get()
                ->keyBy('page_id');

            foreach ($chain as $ancestor) {
                if ($translations->has($ancestor->id)) {
                    $ancestor->setRelation('translations', collect([$translations->get($ancestor->id)]));
                }
            }
        }

        return $chain;
    }

    /**
     * Сгенерировать хлебные крошки для страницы.
     *
     * @return array<int, array{title: string, url: string|null}>
     */
    public function generate(Page $page): array
    {
        $lang = $this->getLangContext();
        $ancestors = $this->loadAncestorChain($page, $lang['currentLang']);

        $breadcrumbs = [];
        foreach ($ancestors as $ancestor) {
            $breadcrumbs[] = [
                'title' => $this->resolveTitle($ancestor, $lang['currentLang'], $lang['isDefaultLang']),
                'url' => $lang['langPrefix'] . $ancestor->url,
            ];
        }

        // Добавляем "Главная" в начало, если её нет
        if (empty($breadcrumbs) || $breadcrumbs[0]['url'] !== $lang['langPrefix'] . '/') {
            array_unshift($breadcrumbs, [
                'title' => 'Главная',
                'url' => $lang['langPrefix'] . '/',
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
     * Сгенерировать хлебные крошки для городской страницы.
     *
     * Формат: Главная → {Город} → ... → Текущая страница
     *
     * @return array<int, array{title: string, url: string|null}>
     */
    public function generateForCity(Page $sourcePage, City $city): array
    {
        $lang = $this->getLangContext();
        $cityPrefix = '/' . $city->slug;
        $ancestors = $this->loadAncestorChain($sourcePage, $lang['currentLang']);

        $breadcrumbs = [];
        foreach ($ancestors as $ancestor) {
            $url = $ancestor->isCitySource()
                ? $lang['langPrefix'] . $cityPrefix . $ancestor->url
                : $lang['langPrefix'] . $ancestor->url;

            $breadcrumbs[] = [
                'title' => $this->resolveTitle($ancestor, $lang['currentLang'], $lang['isDefaultLang']),
                'url' => $url,
            ];
        }

        // Добавляем "Главная" в начало
        if (empty($breadcrumbs) || $breadcrumbs[0]['url'] !== $lang['langPrefix'] . '/') {
            array_unshift($breadcrumbs, [
                'title' => 'Главная',
                'url' => $lang['langPrefix'] . '/',
            ]);
        }

        // Вставляем город после "Главная"
        array_splice($breadcrumbs, 1, 0, [[
            'title' => $city->name,
            'url' => $lang['langPrefix'] . $cityPrefix . '/',
        ]]);

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

    /**
     * Сгенерировать JSON-LD для городских хлебных крошек.
     */
    public function generateJsonLdForCity(Page $sourcePage, City $city): array
    {
        $breadcrumbs = $this->generateForCity($sourcePage, $city);

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
