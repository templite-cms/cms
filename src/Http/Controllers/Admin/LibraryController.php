<?php

namespace Templite\Cms\Http\Controllers\Admin;

use Illuminate\Routing\Controller;
use Inertia\Inertia;
use Inertia\Response;
use Templite\Cms\Models\Library;

class LibraryController extends Controller
{
    /**
     * Список библиотек.
     * Экран: Libraries/Index
     */
    public function index(): Response
    {
        return Inertia::render('Libraries/Index', [
            'libraries' => Library::withCount(['blocks', 'templatePages'])
                ->orderBy('sort_order')
                ->orderBy('name')
                ->get(),
        ]);
    }

    /**
     * Создание новой библиотеки.
     * Экран: Libraries/Edit (пустая форма)
     */
    public function create(): Response
    {
        return Inertia::render('Libraries/Edit', [
            'library' => null,
        ]);
    }

    /**
     * Редактирование библиотеки.
     * Экран: Libraries/Edit
     */
    public function edit(int $id): Response
    {
        return Inertia::render('Libraries/Edit', [
            'library' => Library::withCount(['blocks', 'templatePages'])->findOrFail($id),
        ]);
    }
}
