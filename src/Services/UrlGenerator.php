<?php

namespace Templite\Cms\Services;

use Illuminate\Support\Str;
use Templite\Cms\Models\Page;

class UrlGenerator
{
    /**
     * Сгенерировать URL страницы на основе родителя и alias.
     */
    public function generateUrl(string $alias, ?int $parentId = null): string
    {
        $slug = Str::slug($alias);

        if ($parentId) {
            $parent = Page::find($parentId);
            if ($parent) {
                return rtrim($parent->url, '/') . '/' . $slug;
            }
        }

        return '/' . $slug;
    }

    /**
     * Пересчитать URL страницы и её потомков после смены alias или родителя.
     *
     * Страница с ручным URL (url_auto = false) не меняется — и её потомки тоже:
     * они строятся от URL родителя, а он остался прежним.
     */
    public function updateUrlTree(Page $page): void
    {
        if (!$page->url_auto) {
            return;
        }

        $baseUrl = $this->generateUrl($page->alias, $page->parent_id);

        // Уже соответствует дереву (с учётом суффикса уникальности) — не трогаем
        if ($page->url === $baseUrl || preg_match('#^' . preg_quote($baseUrl, '#') . '-\d+$#', $page->url)) {
            return;
        }

        $page->update(['url' => $this->generateUniqueUrl($page->alias, $page->parent_id, $page->id)]);

        $this->updateChildrenUrls($page);
    }

    /**
     * Пересчитать URL потомков страницы, у которой изменился URL.
     */
    public function updateChildrenUrls(Page $page): void
    {
        foreach (Page::where('parent_id', $page->id)->get() as $child) {
            $this->updateUrlTree($child);
        }
    }

    /**
     * Привести введённый вручную URL к виду /a/b/c (без завершающего слэша).
     */
    public function normalizeUrl(string $url): string
    {
        $url = '/' . trim(mb_strtolower(trim($url)), '/');

        return preg_replace('#/+#', '/', $url);
    }

    /**
     * Проверить уникальность URL.
     */
    public function isUrlUnique(string $url, ?int $excludeId = null): bool
    {
        $query = Page::where('url', $url);

        if ($excludeId) {
            $query->where('id', '!=', $excludeId);
        }

        return !$query->exists();
    }

    /**
     * Сгенерировать уникальный URL (добавляя суффикс при конфликте).
     */
    public function generateUniqueUrl(string $alias, ?int $parentId = null, ?int $excludeId = null): string
    {
        $baseUrl = $this->generateUrl($alias, $parentId);
        $url = $baseUrl;
        $counter = 1;

        while (!$this->isUrlUnique($url, $excludeId)) {
            $url = $baseUrl . '-' . $counter;
            $counter++;
        }

        return $url;
    }
}
