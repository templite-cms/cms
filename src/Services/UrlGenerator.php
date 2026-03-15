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
     * Обновить URL страницы и всех потомков.
     */
    public function updateUrlTree(Page $page): void
    {
        $newUrl = $this->generateUrl($page->alias, $page->parent_id);
        $oldUrl = $page->url;

        if ($newUrl === $oldUrl) {
            return;
        }

        $page->update(['url' => $newUrl]);

        // Обновляем URL всех дочерних страниц
        $children = Page::where('parent_id', $page->id)->get();
        foreach ($children as $child) {
            $this->updateUrlTree($child);
        }
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
