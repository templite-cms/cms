<?php

namespace Templite\Cms\Http\Controllers\Admin;

use Illuminate\Routing\Controller;
use Inertia\Inertia;
use Inertia\Response;
use Templite\Cms\Models\BlockType;

class BlockTypeController extends Controller
{
    /**
     * Список типов блоков.
     * Экран: BlockTypes/Index
     */
    public function index(): Response
    {
        return Inertia::render('BlockTypes/Index', [
            'blockTypes' => BlockType::withCount('blocks')->orderBy('name')->get(),
        ]);
    }

    /**
     * Редактирование типа блока.
     * Экран: BlockTypes/Edit
     */
    public function edit(int $id): Response
    {
        return Inertia::render('BlockTypes/Edit', [
            'blockType' => BlockType::withCount('blocks')->findOrFail($id),
        ]);
    }

    /**
     * Создание нового типа блока.
     * Экран: BlockTypes/Edit (пустая форма)
     */
    public function create(): Response
    {
        return Inertia::render('BlockTypes/Edit', [
            'blockType' => null,
        ]);
    }
}
