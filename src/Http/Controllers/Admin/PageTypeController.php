<?php

namespace Templite\Cms\Http\Controllers\Admin;

use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Templite\Cms\Http\CmsResponse;
use Templite\Cms\Models\PageType;

class PageTypeController extends Controller
{
    /**
     * Список типов страниц.
     * Экран: PageTypes/Index
     */
    public function index()
    {
        return CmsResponse::page('packages/templite/cms/resources/js/entries/page-types-index.js', [
            'pageTypes' => PageType::with('templatePage')->withCount(['pages', 'attributes'])->orderBy('name')->get(),
        ], ['title' => 'Типы страниц']);
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

        return CmsResponse::page('packages/templite/cms/resources/js/entries/page-types-edit.js', [
            'pageType' => $pageType,
        ], ['title' => $pageType->name]);
    }

    /**
     * Создание нового типа страницы.
     * Экран: PageTypes/Edit (пустая форма)
     */
    public function create(): Response
    {
        return CmsResponse::page('packages/templite/cms/resources/js/entries/page-types-edit.js', [
            'pageType' => null,
        ], ['title' => 'Новый тип страницы']);
    }
}
