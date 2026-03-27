<?php

namespace Templite\Cms\Http\Controllers\Admin;

use Illuminate\Routing\Controller;
use Templite\Cms\Http\CmsResponse;
use Templite\Cms\Models\Block;
use Templite\Cms\Models\CmsConfig;
use Templite\Cms\Models\Language;
use Templite\Cms\Models\Page;
use Templite\Cms\Models\PageType;
use Templite\Cms\Helpers\StringHelper;
use Templite\Cms\Models\TemplatePage;
use Templite\Cms\Services\HandlerRegistry;

class PageController extends Controller
{
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

        // Flat list — for cards (with filters)
        $query = Page::with(['pageType', 'screenshot'])->orderBy('order');

        if ($search = $request->input('search')) {
            $query->where('title', 'like', '%' . StringHelper::escapeLike($search) . '%');
        }
        if ($type = $request->input('type')) {
            $query->where('type_id', $type);
        }
        if ($request->has('status') && $request->input('status') !== null && $request->input('status') !== '') {
            $query->where('status', (int) $request->input('status'));
        }

        $pages = $query->get()->map(fn ($page) => [
            'id' => $page->id,
            'title' => $page->title,
            'alias' => $page->alias,
            'status' => $page->status,
            'order' => $page->order,
            'parent_id' => $page->parent_id,
            'page_type' => $page->pageType ? ['id' => $page->pageType->id, 'name' => $page->pageType->name] : null,
            'screenshot_url' => $page->screenshot?->url(),
        ]);

        return CmsResponse::page('packages/templite/cms/resources/js/entries/pages-index.js', [
            'pages' => $pages,
            'tree' => $tree,
            'pageTypes' => PageType::orderBy('name')->get(['id', 'name', 'slug']),
            'filters' => [
                'search' => $request->input('search', ''),
                'type' => $request->input('type', ''),
                'status' => $request->input('status', ''),
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
            'pageBlocks.block.tabs',
            'pageBlocks.block.sections',
            'parent',
            'children',
            'templatePage.tabs',
            'templatePage.sections',
            'templatePage.rootFields.children.children',
        ])->findOrFail($id);

        return CmsResponse::page('packages/templite/cms/resources/js/entries/pages-edit.js', [
            'page' => $page,
            'pageTypes' => PageType::with('attributes')->orderBy('name')->get(),
            'templates' => TemplatePage::orderBy('name')->get(['id', 'name']),
            'parentOptions' => Page::where('id', '!=', $id)
                ->orderBy('title')
                ->get(['id', 'title']),
            'availableBlocks' => $this->getAvailableBlocks(),
            'multicityEnabled' => (bool) CmsConfig::getValue('multicity_enabled', false),
            'multilanguageEnabled' => (bool) CmsConfig::getValue('multilang_enabled', false),
            'languages' => CmsConfig::getValue('multilang_enabled', false)
                ? Language::active()->ordered()->get()
                : [],
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
            'parentOptions' => Page::orderBy('title')->get(['id', 'title']),
            'availableBlocks' => $this->getAvailableBlocks(),
            'multicityEnabled' => (bool) CmsConfig::getValue('multicity_enabled', false),
            'multilanguageEnabled' => (bool) CmsConfig::getValue('multilang_enabled', false),
            'languages' => CmsConfig::getValue('multilang_enabled', false)
                ? Language::active()->ordered()->get()
                : [],
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
