<?php

namespace Templite\Cms\Http\Controllers\Admin;

use Illuminate\Routing\Controller;
use Inertia\Inertia;
use Inertia\Response;
use Templite\Cms\Models\Action;
use Templite\Cms\Models\Block;
use Templite\Cms\Models\BlockType;
use Templite\Cms\Models\GlobalField;
use Templite\Cms\Models\Component;
use Templite\Cms\Models\Library;
use Templite\Cms\Models\PageTypeAttribute;
use Templite\Cms\Models\TemplatePage;
use Templite\Cms\Services\BlockRenderer;

class BlockController extends Controller
{
    public function __construct(protected BlockRenderer $blockRenderer) {}

    /**
     * Список блоков.
     * Экран: Blocks/Index
     */
    public function index(): Response
    {
        return Inertia::render('Blocks/Index', [
            'blocks' => Block::with(['blockType', 'screenshot'])
                ->withCount('pageBlocks')
                ->orderBy('name')
                ->get()
                ->map(fn ($block) => [
                    'id' => $block->id,
                    'name' => $block->name,
                    'slug' => $block->slug,
                    'block_type_id' => $block->block_type_id,
                    'source' => $block->source,
                    'page_blocks_count' => $block->page_blocks_count,
                    'screenshot_url' => $block->screenshot?->url(),
                ]),
            'blockTypes' => BlockType::orderBy('name')->get(['id', 'name', 'slug']),
        ]);
    }

    /**
     * Редактирование блока.
     * Экран: Blocks/Edit
     *
     * BF-017: Загрузка fields (плоский массив всех полей),
     * tabs и sections отдельными коллекциями.
     */
    public function edit(int $id): Response
    {
        $block = Block::with([
            'blockType',
            'tabs' => fn ($q) => $q->orderBy('order'),
            'sections' => fn ($q) => $q->orderBy('order'),
            'fields' => fn ($q) => $q->orderBy('order'),
            'blockActions' => fn ($q) => $q->orderBy('order'),
            'blockActions.action',
            'libraries',
        ])->findOrFail($id);

        // Загружаем код блока из файлов
        $code = ['template_code' => '', 'style_code' => '', 'script_code' => ''];
        $path = $this->blockRenderer->resolveBlockPath($block);
        if ($path) {
            if (file_exists($path . '/template.blade.php')) {
                $code['template_code'] = file_get_contents($path . '/template.blade.php');
            }
            if (file_exists($path . '/style.scss')) {
                $code['style_code'] = file_get_contents($path . '/style.scss');
            } elseif (file_exists($path . '/style.css')) {
                $code['style_code'] = file_get_contents($path . '/style.css');
            }
            if (file_exists($path . '/script.js')) {
                $code['script_code'] = file_get_contents($path . '/script.js');
            }
        }

        // Мержим код в данные блока
        $blockData = array_merge($block->toArray(), $code);

        return Inertia::render('Blocks/Edit', [
            'block' => $blockData,
            'blockTypes' => BlockType::orderBy('name')->get(['id', 'name', 'slug']),
            'templates' => TemplatePage::orderBy('name')->get(['id', 'name', 'slug']),
            'availableActions' => Action::orderBy('name')->get(),
            'globalFieldDefinitions' => GlobalField::orderBy('order')
                ->get(['id', 'key', 'name', 'type', 'parent_id']),
            'pageAttributeDefinitions' => PageTypeAttribute::with('pageType:id,name')
                ->orderBy('order')
                ->get(['id', 'page_type_id', 'key', 'name', 'type'])
                ->map(fn ($attr) => [
                    'key' => $attr->key,
                    'name' => $attr->name,
                    'type' => $attr->type,
                    'page_type_name' => $attr->pageType->name ?? 'Без типа',
                ]),
            'allLibraries' => Library::where('active', true)->orderBy('name')->get(['id', 'name', 'slug', 'version']),
            'componentDefinitions' => Component::orderBy('name')
                ->get(['id', 'name', 'slug', 'params', 'description', 'source']),
        ]);
    }

    /**
     * Создание нового блока.
     * Экран: Blocks/Edit (пустая форма)
     */
    public function create(): Response
    {
        return Inertia::render('Blocks/Edit', [
            'block' => null,
            'blockTypes' => BlockType::orderBy('name')->get(['id', 'name', 'slug']),
            'globalFieldDefinitions' => GlobalField::orderBy('order')
                ->get(['id', 'key', 'name', 'type', 'parent_id']),
            'pageAttributeDefinitions' => PageTypeAttribute::with('pageType:id,name')
                ->orderBy('order')
                ->get(['id', 'page_type_id', 'key', 'name', 'type'])
                ->map(fn ($attr) => [
                    'key' => $attr->key,
                    'name' => $attr->name,
                    'type' => $attr->type,
                    'page_type_name' => $attr->pageType->name ?? 'Без типа',
                ]),
            'componentDefinitions' => Component::orderBy('name')
                ->get(['id', 'name', 'slug', 'params', 'description', 'source']),
        ]);
    }
}
