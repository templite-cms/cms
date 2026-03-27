<?php

namespace Templite\Cms\Http\Controllers\Admin;

use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Templite\Cms\Http\CmsResponse;
use Templite\Cms\Models\Block;
use Templite\Cms\Models\BlockPreset;
use Templite\Cms\Models\BlockType;
use Templite\Cms\Models\TemplatePage;

class PresetController extends Controller
{
    /**
     * Список пресетов блоков.
     * Экран: Presets/Index
     */
    public function index()
    {
        $presets = BlockPreset::with(['block:id,name,slug,block_type_id', 'screenFile'])
            ->orderBy('order')
            ->get()
            ->map(function ($preset) {
                $preset->screenshot_url = $preset->screenFile?->url;
                $preset->block_name = $preset->block?->name;
                $preset->block_type_id = $preset->block?->block_type_id;
                return $preset;
            });

        $blockTypes = BlockType::orderBy('name')->get();
        $blocks = Block::select('id', 'name', 'slug', 'block_type_id')->orderBy('name')->get();

        return CmsResponse::page('packages/templite/cms/resources/js/entries/presets-index.js', [
            'presets' => $presets,
            'blockTypes' => $blockTypes,
            'blocks' => $blocks,
        ], ['title' => 'Пресеты']);
    }

    /**
     * Редактирование пресета.
     * Экран: Presets/Edit
     */
    public function edit(int $id): Response
    {
        $preset = BlockPreset::with([
            'block.fields.children.children',
            'block.tabs',
            'block.sections',
            'block.blockType',
            'block.libraries',
            'screenFile',
        ])->findOrFail($id);

        $preset->screenshot_url = $preset->screenFile?->url;

        $blocks = Block::select('id', 'name', 'slug', 'block_type_id')->orderBy('name')->get();
        $blockTypes = BlockType::orderBy('name')->get();
        $templates = TemplatePage::select('id', 'name')->orderBy('name')->get();

        return CmsResponse::page('packages/templite/cms/resources/js/entries/presets-edit.js', [
            'preset' => $preset,
            'blocks' => $blocks,
            'blockTypes' => $blockTypes,
            'templates' => $templates,
        ], ['title' => $preset->name ?? 'Редактирование пресета']);
    }
}
