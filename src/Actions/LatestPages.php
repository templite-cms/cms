<?php

namespace Templite\Cms\Actions;

use Templite\Cms\Contracts\ActionContext;
use Templite\Cms\Contracts\BlockActionInterface;
use Templite\Cms\Models\Page;

/**
 * Action: Последние страницы.
 *
 * Возвращает N последних опубликованных страниц заданного типа.
 * Применение: блоки "Последние новости", "Свежие проекты".
 */
class LatestPages implements BlockActionInterface
{
    public function params(): array
    {
        return [
            'page_type_id' => 'integer|nullable',
            'page_type_slug' => 'string|nullable',
            'limit' => 'integer',
            'exclude_current' => 'boolean',
            'order_by' => 'string',
        ];
    }

    public function returns(): array
    {
        return [
            'pages' => 'array',
        ];
    }

    public function handle(array $params, ActionContext $context): array
    {
        $limit = $params['limit'] ?? 6;
        $orderBy = $params['order_by'] ?? 'created_at';

        $query = Page::with(['pageType', 'attributeValues'])
            ->where('is_published', true);

        // Фильтр по типу страницы
        if (!empty($params['page_type_id'])) {
            $query->where('type_id', $params['page_type_id']);
        } elseif (!empty($params['page_type_slug'])) {
            $query->whereHas('pageType', fn ($q) => $q->where('slug', $params['page_type_slug']));
        }

        // Исключить текущую страницу
        if (!empty($params['exclude_current']) && $context->page->id) {
            $query->where('id', '!=', $context->page->id);
        }

        $pages = $query->orderByDesc($orderBy)->limit($limit)->get();

        return [
            'pages' => $pages->toArray(),
        ];
    }
}
