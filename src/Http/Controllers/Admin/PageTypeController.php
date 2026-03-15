<?php

namespace Templite\Cms\Http\Controllers\Admin;

use Illuminate\Routing\Controller;
use Inertia\Inertia;
use Inertia\Response;
use Templite\Cms\Models\PageType;

class PageTypeController extends Controller
{
    /**
     * Список типов страниц.
     * Экран: PageTypes/Index
     */
    public function index(): Response
    {
        return Inertia::render('PageTypes/Index', [
            'pageTypes' => PageType::withCount(['pages', 'attributes'])->orderBy('name')->get(),
        ]);
    }

    /**
     * Редактирование типа страницы.
     * Экран: PageTypes/Edit
     */
    public function edit(int $id): Response
    {
        $pageType = PageType::with([
            'attributes' => fn ($q) => $q->orderBy('order'),
        ])->withCount('pages')->findOrFail($id);

        return Inertia::render('PageTypes/Edit', [
            'pageType' => $pageType,
        ]);
    }

    /**
     * Создание нового типа страницы.
     * Экран: PageTypes/Edit (пустая форма)
     */
    public function create(): Response
    {
        return Inertia::render('PageTypes/Edit', [
            'pageType' => null,
        ]);
    }
}
