<?php

namespace Templite\Cms\Http\Controllers\Admin;

use Illuminate\Routing\Controller;
use Templite\Cms\Http\CmsResponse;
use Templite\Cms\Models\Block;
use Templite\Cms\Models\Page;
use Templite\Cms\Models\PageType;
use Templite\Cms\Helpers\StringHelper;
use Templite\Cms\Models\TemplatePage;
use Templite\Cms\Services\HandlerRegistry;

class PageController extends Controller
{
    /** Колонки, по которым список страниц можно сортировать. */
    protected const SORT_FIELDS = ['id', 'title', 'status', 'updated_at'];

    /** Допустимые размеры страницы списка; второй — по умолчанию. */
    protected const PER_PAGE_OPTIONS = [12, 24, 48, 96];

    /**
     * Список страниц (дерево).
     * Экран: Pages/Index
     */
    public function index()
    {
        $request = request();

        // Tree — always full (for left sidebar), recursive children loading
        $tree = Page::with(['pageType', 'children' => fn ($q) => $q->orderBy('order')])
            ->whereNull('parent_id')
            ->orderBy('order')
            ->get()
            ->each(fn ($page) => $this->loadChildrenRecursive($page))
            ->map(fn ($page) => $this->formatPageTree($page));

        // Flat list — for cards/table (with filters, server pagination)
        $query = Page::with(['pageType', 'screenshot', 'creator']);

        if ($search = $request->input('search')) {
            $escaped = StringHelper::escapeLike($search);
            $query->where(fn ($q) => $q->where('title', 'like', "%{$escaped}%")
                ->orWhere('url', 'like', "%{$escaped}%"));
        }
        if ($type = $request->input('type')) {
            $query->where('type_id', $type);
        }
        if ($request->filled('status')) {
            $query->where('status', (int) $request->input('status'));
        }

        $sortField = in_array($request->input('sort_field'), self::SORT_FIELDS, true)
            ? $request->input('sort_field')
            : null;
        $sortOrder = $request->input('sort_order') === 'desc' ? 'desc' : 'asc';

        if ($sortField) {
            $query->orderBy($sortField, $sortOrder);
        } else {
            $query->orderBy('order');
        }
        $query->orderBy('id');

        $perPage = in_array((int) $request->input('per_page'), self::PER_PAGE_OPTIONS, true)
            ? (int) $request->input('per_page')
            : self::PER_PAGE_OPTIONS[1];
        $pages = $query->paginate($perPage);

        return CmsResponse::page('packages/templite/cms/resources/js/entries/pages-index.js', [
            'pages' => [
                'data' => $pages->map(fn ($page) => [
                    'id' => $page->id,
                    'title' => $page->title,
                    'alias' => $page->alias,
                    'full_url' => $page->full_url,
                    'url_auto' => $page->url_auto,
                    'status' => $page->status,
                    'order' => $page->order,
                    'parent_id' => $page->parent_id,
                    'page_type' => $page->pageType ? ['id' => $page->pageType->id, 'name' => $page->pageType->name] : null,
                    'creator' => $page->creator ? ['id' => $page->creator->id, 'name' => $page->creator->name ?: $page->creator->login] : null,
                    'screenshot_url' => $page->screenshot?->url(),
                    'updated_at' => $page->updated_at?->toIso8601String(),
                ]),
                'meta' => [
                    'current_page' => $pages->currentPage(),
                    'last_page' => $pages->lastPage(),
                    'per_page' => $pages->perPage(),
                    'total' => $pages->total(),
                    'from' => $pages->firstItem(),
                    'to' => $pages->lastItem(),
                ],
            ],
            'tree' => $tree,
            'pageTypes' => PageType::orderBy('name')->get(['id', 'name', 'slug']),
            'perPageOptions' => self::PER_PAGE_OPTIONS,
            'filters' => [
                'search' => $request->input('search', ''),
                'type' => $request->input('type', ''),
                'status' => $request->input('status', ''),
                'sort_field' => $sortField,
                'sort_order' => $sortOrder,
                'per_page' => $perPage,
            ],
        ], ['title' => 'Страницы']);
    }

    /**
     * Редактирование страницы.
     * Экран: Pages/Edit
     */
    public function edit(int $id)
    {
        $page = Page::with([
            'pageType.attributes',
            'attributeValues',
            'pageBlocks.preset',
            'pageBlocks.block.blockType',
            'pageBlocks.block.blockActions.action',
            'pageBlocks.block.fields.children.children',
            'parent',
            'children',
            'templatePage.rootFields.children.children',
        ])->findOrFail($id);

        return CmsResponse::page('packages/templite/cms/resources/js/entries/pages-edit.js', [
            'page' => $page,
            'pageTypes' => PageType::with('attributes')->orderBy('name')->get(),
            'templates' => TemplatePage::orderBy('name')->get(['id', 'name']),
            // Себя и свои вложенные страницы родителем сделать нельзя
            'parentOptions' => Page::whereNotIn('id', [$id, ...$page->descendantIds()])
                ->orderBy('title')
                ->get(['id', 'title', 'url']),
            'availableBlocks' => $this->getAvailableBlocks(),
            'handlers' => app(HandlerRegistry::class)->all(),
        ], ['title' => 'Редактирование страницы']);
    }


    /**
     * Создание новой страницы.
     * Экран: Pages/Edit (пустая форма)
     */
    public function create()
    {
        return CmsResponse::page('packages/templite/cms/resources/js/entries/pages-edit.js', [
            'page' => null,
            'pageTypes' => PageType::with('attributes')->orderBy('name')->get(),
            'templates' => TemplatePage::orderBy('name')->get(['id', 'name']),
            'parentOptions' => Page::orderBy('title')->get(['id', 'title', 'url']),
            'availableBlocks' => $this->getAvailableBlocks(),
            'handlers' => app(HandlerRegistry::class)->all(),
        ], ['title' => 'Новая страница']);
    }

    /**
     * Получение списка доступных блоков для каталога.
     */
    protected function getAvailableBlocks(): \Illuminate\Support\Collection
    {
        return Block::with(['blockType', 'screenshot', 'presets.screenFile'])
            ->orderBy('block_type_id')
            ->orderBy('name')
            ->get()
            ->map(fn ($b) => [
                'id' => $b->id,
                'name' => $b->name,
                'slug' => $b->slug,
                'screenshot_url' => $b->screenshot?->url(),
                'block_type' => $b->blockType ? [
                    'id' => $b->blockType->id,
                    'name' => $b->blockType->name,
                ] : null,
                'presets' => $b->presets->map(fn ($p) => [
                    'id' => $p->id,
                    'name' => $p->name,
                    'slug' => $p->slug,
                    'type' => $p->type,
                    'is_default' => $p->is_default,
                    'data' => $p->data,
                    'screenshot_url' => $p->screenFile?->url(),
                ]),
            ]);
    }

    /**
     * Рекурсивная подгрузка дочерних страниц.
     */
    protected function loadChildrenRecursive(Page $page): void
    {
        $page->children->each(function ($child) {
            $child->load(['pageType', 'children' => fn ($q) => $q->orderBy('order')]);
            $this->loadChildrenRecursive($child);
        });
    }

    /**
     * Рекурсивное форматирование дерева страниц.
     */
    protected function formatPageTree(Page $page): array
    {
        return [
            'id' => $page->id,
            'title' => $page->title,
            'alias' => $page->alias,
            'full_url' => $page->full_url,
            'status' => $page->status,
            'type' => $page->pageType ? ['id' => $page->pageType->id, 'name' => $page->pageType->name] : null,
            'order' => $page->order,
            'children' => $page->children
                ->sortBy('order')
                ->map(fn ($child) => $this->formatPageTree($child))
                ->values()
                ->toArray(),
        ];
    }
}
