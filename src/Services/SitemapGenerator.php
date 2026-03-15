<?php

namespace Templite\Cms\Services;

use Templite\Cms\Models\City;
use Templite\Cms\Models\CmsConfig;
use Templite\Cms\Models\Language;
use Templite\Cms\Models\Page;

class SitemapGenerator
{
    /**
     * Сгенерировать XML-карту сайта.
     *
     * При включённом мультигороде возвращает sitemap index,
     * иначе — обычный sitemap со всеми страницами.
     */
    public function generate(): string
    {
        if (CmsConfig::getValue('multicity_enabled', false)) {
            return $this->generateSitemapIndex();
        }

        return $this->generateStandard();
    }

    /**
     * Стандартный sitemap (без мультигорода).
     */
    protected function generateStandard(): string
    {
        $pages = Page::published()
            ->orderBy('url')
            ->get();

        $multilangEnabled = CmsConfig::getValue('multilang_enabled', false);
        $languages = $multilangEnabled ? Language::active()->ordered()->get() : collect();

        $xmlns = 'xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"';
        if ($multilangEnabled && $languages->count() > 1) {
            $xmlns .= ' xmlns:xhtml="http://www.w3.org/1999/xhtml"';
        }

        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . PHP_EOL;
        $xml .= '<urlset ' . $xmlns . '>' . PHP_EOL;

        foreach ($pages as $page) {
            $xml .= $this->buildUrlEntry($page, $multilangEnabled, $languages);
        }

        $xml .= '</urlset>';

        return $xml;
    }

    /**
     * Sitemap index для мультигорода.
     */
    protected function generateSitemapIndex(): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . PHP_EOL;
        $xml .= '<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . PHP_EOL;

        // Глобальный sitemap
        $xml .= '  <sitemap>' . PHP_EOL;
        $xml .= '    <loc>' . htmlspecialchars(url('/sitemap-global.xml')) . '</loc>' . PHP_EOL;
        $xml .= '  </sitemap>' . PHP_EOL;

        // Sitemap для каждого активного города
        $cities = City::active()->ordered()->get();

        foreach ($cities as $city) {
            $xml .= '  <sitemap>' . PHP_EOL;
            $xml .= '    <loc>' . htmlspecialchars(url('/sitemap-' . $city->slug . '.xml')) . '</loc>' . PHP_EOL;
            $xml .= '  </sitemap>' . PHP_EOL;
        }

        $xml .= '</sitemapindex>';

        return $xml;
    }

    /**
     * Sitemap для глобальных страниц (не city_source).
     */
    public function generateGlobal(): string
    {
        $pages = Page::published()
            ->where('city_scope', Page::CITY_SCOPE_GLOBAL)
            ->orderBy('url')
            ->get();

        $multilangEnabled = CmsConfig::getValue('multilang_enabled', false);
        $languages = $multilangEnabled ? Language::active()->ordered()->get() : collect();

        $xmlns = 'xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"';
        if ($multilangEnabled && $languages->count() > 1) {
            $xmlns .= ' xmlns:xhtml="http://www.w3.org/1999/xhtml"';
        }

        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . PHP_EOL;
        $xml .= '<urlset ' . $xmlns . '>' . PHP_EOL;

        foreach ($pages as $page) {
            $xml .= $this->buildUrlEntry($page, $multilangEnabled, $languages);
        }

        $xml .= '</urlset>';

        return $xml;
    }

    /**
     * Sitemap для конкретного города.
     *
     * Включает виртуальные страницы (city_source с городским префиксом)
     * и материализованные страницы этого города.
     */
    public function generateForCity(string $citySlug): ?string
    {
        $city = City::where('slug', $citySlug)->active()->first();

        if (!$city) {
            return null;
        }

        $multilangEnabled = CmsConfig::getValue('multilang_enabled', false);
        $languages = $multilangEnabled ? Language::active()->ordered()->get() : collect();

        $xmlns = 'xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"';
        if ($multilangEnabled && $languages->count() > 1) {
            $xmlns .= ' xmlns:xhtml="http://www.w3.org/1999/xhtml"';
        }

        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . PHP_EOL;
        $xml .= '<urlset ' . $xmlns . '>' . PHP_EOL;

        // Виртуальные страницы (из city_source)
        $sourcePages = Page::published()
            ->where('city_scope', Page::CITY_SCOPE_CITY_SOURCE)
            ->orderBy('url')
            ->get();

        foreach ($sourcePages as $page) {
            $cityUrl = '/' . $city->slug . $page->url;

            // Проверяем, не скрыта ли страница для города
            $cityPage = $page->cityPages()
                ->where('city_id', $city->id)
                ->first();

            // Если есть оверрайд статуса и страница скрыта — пропускаем
            if ($cityPage && $cityPage->status_override !== null && $cityPage->status_override !== 1) {
                continue;
            }

            // Если материализована — URL будет отличаться, пропускаем виртуальный
            if ($cityPage && $cityPage->is_materialized && $cityPage->materialized_page_id) {
                continue;
            }

            $xml .= $this->buildUrlEntryRaw($cityUrl, $page->updated_at, $page, $multilangEnabled, $languages);
        }

        // Материализованные страницы этого города
        $materializedPages = Page::published()
            ->where('city_scope', Page::CITY_SCOPE_MATERIALIZED)
            ->where('city_id', $city->id)
            ->orderBy('url')
            ->get();

        foreach ($materializedPages as $page) {
            $xml .= $this->buildUrlEntry($page, $multilangEnabled, $languages);
        }

        $xml .= '</urlset>';

        return $xml;
    }

    /**
     * Построить запись URL.
     */
    protected function buildUrlEntry(Page $page, bool $multilangEnabled = false, $languages = null): string
    {
        $baseUrl = rtrim(config('app.url', url('/')), '/');
        $url = $page->url;
        $loc = $baseUrl . $url;
        $lastmod = $page->updated_at->toIso8601String();
        $priority = $this->calculatePriority($page);
        $changefreq = $this->calculateChangeFreq($page);

        $entry = '  <url>' . PHP_EOL;
        $entry .= '    <loc>' . htmlspecialchars($loc) . '</loc>' . PHP_EOL;
        $entry .= '    <lastmod>' . $lastmod . '</lastmod>' . PHP_EOL;
        $entry .= '    <changefreq>' . $changefreq . '</changefreq>' . PHP_EOL;
        $entry .= '    <priority>' . number_format($priority, 1) . '</priority>' . PHP_EOL;
        $entry .= $this->buildHreflangLinks($url, $baseUrl, $multilangEnabled, $languages);
        $entry .= '  </url>' . PHP_EOL;

        return $entry;
    }

    /**
     * Построить запись URL из сырых данных.
     */
    protected function buildUrlEntryRaw(string $url, $updatedAt, Page $page, bool $multilangEnabled = false, $languages = null): string
    {
        $baseUrl = rtrim(config('app.url', url('/')), '/');
        $loc = $baseUrl . $url;
        $lastmod = $updatedAt->toIso8601String();
        $priority = $this->calculatePriority($page);
        $changefreq = $this->calculateChangeFreq($page);

        $entry = '  <url>' . PHP_EOL;
        $entry .= '    <loc>' . htmlspecialchars($loc) . '</loc>' . PHP_EOL;
        $entry .= '    <lastmod>' . $lastmod . '</lastmod>' . PHP_EOL;
        $entry .= '    <changefreq>' . $changefreq . '</changefreq>' . PHP_EOL;
        $entry .= '    <priority>' . number_format($priority, 1) . '</priority>' . PHP_EOL;
        $entry .= $this->buildHreflangLinks($url, $baseUrl, $multilangEnabled, $languages);
        $entry .= '  </url>' . PHP_EOL;

        return $entry;
    }

    /**
     * Сформировать hreflang-ссылки для мультиязычного sitemap.
     */
    protected function buildHreflangLinks(string $url, string $baseUrl, bool $multilangEnabled, $languages): string
    {
        if (!$multilangEnabled || !$languages || $languages->count() <= 1) {
            return '';
        }

        $links = '';

        foreach ($languages as $lang) {
            $langPrefix = $lang->is_default ? '' : '/' . $lang->code;
            $links .= '    <xhtml:link rel="alternate" hreflang="' . htmlspecialchars($lang->code) . '" href="' . htmlspecialchars($baseUrl . $langPrefix . $url) . '"/>' . PHP_EOL;
        }

        // x-default указывает на URL без языкового префикса (дефолтный язык)
        $links .= '    <xhtml:link rel="alternate" hreflang="x-default" href="' . htmlspecialchars($baseUrl . $url) . '"/>' . PHP_EOL;

        return $links;
    }

    /**
     * Рассчитать приоритет страницы.
     */
    protected function calculatePriority(Page $page): float
    {
        // Главная страница -- максимальный приоритет
        if ($page->url === '/') {
            return 1.0;
        }

        // Считаем глубину вложенности по URL
        $depth = substr_count(trim($page->url, '/'), '/');

        // Чем глубже -- тем ниже приоритет
        return max(0.1, 0.8 - ($depth * 0.1));
    }

    /**
     * Рассчитать частоту обновления.
     */
    protected function calculateChangeFreq(Page $page): string
    {
        if ($page->url === '/') {
            return 'daily';
        }

        // Если страница обновлялась в последние 7 дней
        if ($page->updated_at->diffInDays(now()) <= 7) {
            return 'weekly';
        }

        // Если обновлялась в последний месяц
        if ($page->updated_at->diffInDays(now()) <= 30) {
            return 'monthly';
        }

        return 'yearly';
    }
}
