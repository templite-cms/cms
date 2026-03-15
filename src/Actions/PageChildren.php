<?php

namespace Templite\Cms\Actions;

use Templite\Cms\Contracts\ActionContext;
use Templite\Cms\Contracts\BlockActionInterface;
use Templite\Cms\Models\Page;

/**
 * Action: Дочерние страницы.
 *
 * Возвращает дочерние страницы текущей страницы или указанной страницы.
 * Применение: подменю, навигация по разделу, список подстраниц.
 */
class PageChildren implements BlockActionInterface
{
    public function params(): array
    {
        return [
            'parent_id' => 'integer|nullable',
            'limit' => 'integer',
            'order_by' => 'string',
            'depth' => 'integer',
        ];
    }

    public function returns(): array
    {
        return [
            'children' => 'array',
        ];
    }

    public function handle(array $params, ActionContext $context): array
    {
        $parentId = $params['parent_id'] ?? $context->page->id;
        $limit = $params['limit'] ?? 50;
        $orderBy = $params['order_by'] ?? 'order';
        $depth = $params['depth'] ?? 1;

        $query = Page::where('parent_id', $parentId)
            ->where('is_published', true)
            ->orderBy($orderBy);

        if ($limit > 0) {
            $query->limit($limit);
        }

        $children = $query->get();

        // Рекурсивно загружаем вложенные уровни
        if ($depth > 1) {
            $children = $this->loadRecursive($children, $depth - 1, $orderBy);
        }

        return [
            'children' => $children->toArray(),
        ];
    }

    protected function loadRecursive($pages, int $remainingDepth, string $orderBy): mixed
    {
        if ($remainingDepth <= 0) {
            return $pages;
        }

        foreach ($pages as $page) {
            $page->setRelation('children',
                $this->loadRecursive(
                    $page->children()->where('is_published', true)->orderBy($orderBy)->get(),
                    $remainingDepth - 1,
                    $orderBy
                )
            );
        }

        return $pages;
    }
}
