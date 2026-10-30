<?php

namespace Templite\Cms\Services;

use Templite\Cms\Models\Page;

class SitemapGenerator
{
    /**
     * Сгенерировать XML-карту сайта со всеми опубликованными страницами.
     */
    public function generate(): string
    {
        $pages = Page::published()
            ->orderBy('url')
            ->get();

        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . PHP_EOL;
        $xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . PHP_EOL;

        foreach ($pages as $page) {
            $xml .= $this->buildUrlEntry($page);
        }

        $xml .= '</urlset>';

        return $xml;
    }

    /**
     * Построить запись URL.
     */
    protected function buildUrlEntry(Page $page): string
    {
        $baseUrl = rtrim(config('app.url', url('/')), '/');
        $loc = $baseUrl . $page->url;
        $lastmod = $page->updated_at->toIso8601String();
        $priority = $this->calculatePriority($page);
        $changefreq = $this->calculateChangeFreq($page);

        $entry = '  <url>' . PHP_EOL;
        $entry .= '    <loc>' . htmlspecialchars($loc) . '</loc>' . PHP_EOL;
        $entry .= '    <lastmod>' . $lastmod . '</lastmod>' . PHP_EOL;
        $entry .= '    <changefreq>' . $changefreq . '</changefreq>' . PHP_EOL;
        $entry .= '    <priority>' . number_format($priority, 1) . '</priority>' . PHP_EOL;
        $entry .= '  </url>' . PHP_EOL;

        return $entry;
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
