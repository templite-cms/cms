<?php

namespace Templite\Cms\Http\Controllers\Admin;

use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Templite\Cms\Http\CmsResponse;
use Templite\Cms\Models\Library;

class LibraryController extends Controller
{
    /**
     * Список библиотек.
     * Экран: Libraries/Index
     */
    public function index()
    {
        return CmsResponse::page('packages/templite/cms/resources/js/entries/libraries-index.js', [
            'libraries' => Library::withCount(['blocks', 'templatePages'])
                ->orderBy('sort_order')
                ->orderBy('name')
                ->get(),
        ], ['title' => 'Библиотеки']);
    }

    /**
     * Создание новой библиотеки.
     * Экран: Libraries/Edit (пустая форма)
     */
    public function create(): Response
    {
        return CmsResponse::page('packages/templite/cms/resources/js/entries/libraries-edit.js', [
            'library' => null,
        ], ['title' => 'Новая библиотека']);
    }

    /**
     * Редактирование библиотеки.
     * Экран: Libraries/Edit
     */
    public function edit(int $id): Response
    {
        return CmsResponse::page('packages/templite/cms/resources/js/entries/libraries-edit.js', [
            'library' => Library::withCount(['blocks', 'templatePages'])->findOrFail($id),
        ], ['title' => 'Редактирование библиотеки']);
    }
}
