<?php

namespace Templite\Cms\Actions;

use Templite\Cms\Contracts\ActionContext;
use Templite\Cms\Contracts\BlockActionInterface;
use Templite\Cms\Models\Page;

/**
 * Action: Результаты поиска.
 *
 * Поиск по страницам CMS с пагинацией.
 * Применение: блок поиска, страница результатов.
 */
class SearchResults implements BlockActionInterface
{
    public function params(): array
    {
        return [
            'query_param' => 'string',
            'per_page' => 'integer',
            'page_type_ids' => 'array',
            'search_in' => 'array',
        ];
    }

    public function returns(): array
    {
        return [
            'query' => 'string',
            'results' => 'array',
            'pagination' => 'array',
        ];
    }

    public function handle(array $params, ActionContext $context): array
    {
        $queryParam = $params['query_param'] ?? 'q';
        $perPage = min((int) ($params['per_page'] ?? 10), 100);
        $searchQuery = $context->request->query($queryParam, '');

        if (empty($searchQuery) || mb_strlen($searchQuery) < 2) {
            return [
                'query' => $searchQuery,
                'results' => [],
                'pagination' => [
                    'current_page' => 1,
                    'last_page' => 1,
                    'per_page' => $perPage,
                    'total' => 0,
                ],
            ];
        }

        $searchIn = $params['search_in'] ?? ['title', 'content'];
        $searchTerm = '%' . $searchQuery . '%';

        $query = Page::where('is_published', true);

        // Фильтр по типам страниц
        if (!empty($params['page_type_ids'])) {
            $query->whereIn('type_id', $params['page_type_ids']);
        }

        // Поиск по полям
        $query->where(function ($q) use ($searchIn, $searchTerm) {
            foreach ($searchIn as $field) {
                if ($field === 'title') {
                    $q->orWhere('title', 'like', $searchTerm);
                } elseif ($field === 'content') {
                    $q->orWhere('content', 'like', $searchTerm);
                } elseif ($field === 'slug') {
                    $q->orWhere('slug', 'like', $searchTerm);
                }
            }
        });

        $paginator = $query->orderByDesc('updated_at')->paginate($perPage);

        return [
            'query' => $searchQuery,
            'results' => $paginator->items(),
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'has_more_pages' => $paginator->hasMorePages(),
            ],
        ];
    }
}
