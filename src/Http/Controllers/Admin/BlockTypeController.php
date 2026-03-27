<?php

namespace Templite\Cms\Http\Controllers\Admin;

use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Templite\Cms\Http\CmsResponse;
use Templite\Cms\Models\BlockType;

class BlockTypeController extends Controller
{
    /**
     * Список типов блоков.
     * Экран: BlockTypes/Index
     */
    public function index()
    {
        return CmsResponse::page('packages/templite/cms/resources/js/entries/block-types-index.js', [
            'blockTypes' => BlockType::withCount('blocks')->orderBy('name')->get(),
        ], ['title' => 'Типы блоков']);
    }

    /**
     * Редактирование типа блока.
     * Экран: BlockTypes/Edit
     */
    public function edit(int $id): Response
    {
        return CmsResponse::page('packages/templite/cms/resources/js/entries/block-types-edit.js', [
            'blockType' => BlockType::withCount('blocks')->findOrFail($id),
        ], ['title' => 'Редактирование типа блока']);
    }

    /**
     * Создание нового типа блока.
     * Экран: BlockTypes/Edit (пустая форма)
     */
    public function create(): Response
    {
        return CmsResponse::page('packages/templite/cms/resources/js/entries/block-types-edit.js', [
            'blockType' => null,
        ], ['title' => 'Новый тип блока']);
    }
}
