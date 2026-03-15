<?php

namespace Templite\Cms\Actions;

use Templite\Cms\Contracts\ActionContext;
use Templite\Cms\Contracts\BlockActionInterface;
use Templite\Cms\Helpers\StringHelper;
use Templite\Cms\Models\Page;

/**
 * Action: Фильтрованные страницы.
 *
 * Возвращает пагинированный список страниц с фильтрацией
 * по атрибутам, типу, тегам. Поддерживает GET-параметры из URL.
 * Применение: каталог проектов, блог с фильтрами.
 */
class FilteredPages implements BlockActionInterface
{
    public function params(): array
    {
        return [
            'page_type_id' => 'integer|nullable',
            'page_type_slug' => 'string|nullable',
            'per_page' => 'integer',
            'order_by' => 'string',
            'order_dir' => 'string',
            'filterable_attributes' => 'array',
        ];
    }

    public function returns(): array
    {
        return [
            'pages' => 'array',
            'pagination' => 'array',
            'filters' => 'array',
            'active_filters' => 'array',
        ];
    }

    public function handle(array $params, ActionContext $context): array
    {
        $perPage = min((int) ($params['per_page'] ?? 12), 100);
        $orderBy = $params['order_by'] ?? 'created_at';
        $orderDir = $params['order_dir'] ?? 'desc';

        $query = Page::with(['pageType', 'attributeValues'])
            ->where('is_published', true);

        // Фильтр по типу страницы
        if (!empty($params['page_type_id'])) {
            $query->where('type_id', $params['page_type_id']);
        } elseif (!empty($params['page_type_slug'])) {
            $query->whereHas('pageType', fn ($q) => $q->where('slug', $params['page_type_slug']));
        }

        // Фильтрация по GET-параметрам
        $activeFilters = [];
        $request = $context->request;

        if (!empty($params['filterable_attributes'])) {
            foreach ($params['filterable_attributes'] as $attrSlug) {
                $value = $request->query($attrSlug);
                if ($value !== null) {
                    $activeFilters[$attrSlug] = $value;
                    $query->whereHas('attributeValues', function ($q) use ($attrSlug, $value) {
                        $q->whereHas('attribute', fn ($aq) => $aq->where('slug', $attrSlug))
                          ->where('value', $value);
                    });
                }
            }
        }

        // Поиск
        $search = $request->query('search');
        if ($search) {
            $query->where(function ($q) use ($search) {
                $escaped = StringHelper::escapeLike($search);
                $q->where('title', 'like', "%{$escaped}%")
                  ->orWhere('content', 'like', "%{$escaped}%");
            });
            $activeFilters['search'] = $search;
        }

        // Сортировка
        $query->orderBy($orderBy, $orderDir);

        // Пагинация
        $paginator = $query->paginate($perPage);

        return [
            'pages' => $paginator->items(),
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'has_more_pages' => $paginator->hasMorePages(),
            ],
            'filters' => $params['filterable_attributes'] ?? [],
            'active_filters' => $activeFilters,
        ];
    }
}
