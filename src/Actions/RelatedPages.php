<?php

namespace Templite\Cms\Actions;

use Templite\Cms\Contracts\ActionContext;
use Templite\Cms\Contracts\BlockActionInterface;
use Templite\Cms\Models\Page;

/**
 * Action: Связанные страницы.
 *
 * Возвращает страницы, связанные через page_to_page или по типу.
 * Применение: "Похожие проекты", "Вам может быть интересно".
 */
class RelatedPages implements BlockActionInterface
{
    public function params(): array
    {
        return [
            'limit' => 'integer',
            'use_relations' => 'boolean',
            'fallback_same_type' => 'boolean',
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
        $limit = $params['limit'] ?? 4;
        $useRelations = $params['use_relations'] ?? true;
        $fallbackSameType = $params['fallback_same_type'] ?? true;

        $pages = collect();

        // 1. Из связей page_to_page
        if ($useRelations && $context->page->id) {
            $pages = $context->page->relatedPages()
                ->where('is_published', true)
                ->limit($limit)
                ->get();
        }

        // 2. Fallback: страницы того же типа
        if ($fallbackSameType && $pages->count() < $limit && $context->page->type_id) {
            $existing = $pages->pluck('id')->push($context->page->id);

            $additional = Page::where('type_id', $context->page->type_id)
                ->where('is_published', true)
                ->whereNotIn('id', $existing)
                ->inRandomOrder()
                ->limit($limit - $pages->count())
                ->get();

            $pages = $pages->concat($additional);
        }

        return [
            'pages' => $pages->toArray(),
        ];
    }
}
