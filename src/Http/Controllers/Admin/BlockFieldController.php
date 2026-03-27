<?php

namespace Templite\Cms\Http\Controllers\Admin;

use Illuminate\Routing\Controller;
use Templite\Cms\Http\CmsResponse;
use Templite\Cms\Models\Block;
use Templite\Cms\Models\BlockField;

class BlockFieldController extends Controller
{
    /**
     * Управление полями блока (встраивается в Blocks/Edit).
     * Возвращает дерево полей блока для AJAX-подгрузки.
     * Экран: Blocks/Edit (вкладка "Поля")
     *
     * BF-017: Загрузка fields как дерево (rootFields с children),
     * tabs и sections отдельными коллекциями.
     */
    public function index(int $blockId)
    {
        $block = Block::with([
            'tabs' => fn ($q) => $q->orderBy('order'),
            'sections' => fn ($q) => $q->orderBy('order'),
            'rootFields' => fn ($q) => $q->orderBy('order'),
            'rootFields.children' => fn ($q) => $q->orderBy('order'),
            'rootFields.children.children' => fn ($q) => $q->orderBy('order'),
        ])->findOrFail($blockId);

        return CmsResponse::page('packages/templite/cms/resources/js/entries/blocks-fields.js', [
            'block' => $block,
            'fieldTypes' => config('cms.field_types', BlockField::FIELD_TYPES),
        ], ['title' => 'Поля блока']);
    }
}
