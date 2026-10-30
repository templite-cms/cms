<?php

namespace Templite\Cms\Services\Ai\Tools;

use Templite\Cms\Models\Page;
use Templite\Cms\Models\PageBlock;
use Templite\Cms\Services\Ai\EditorContext;

class GetPageTool implements Tool
{
    public function name(): string
    {
        return 'get_page';
    }

    public function definition(): array
    {
        return [
            'type' => 'object',
            'properties' => ['page_id' => ['type' => 'integer', 'description' => 'ID страницы']],
            'required' => ['page_id'],
        ];
    }

    public function execute(array $args, ?EditorContext $ctx): array
    {
        $pageId = (int) ($args['page_id'] ?? 0);

        // Если запрошена открытая в редакторе страница — отдаём live-снапшот (с несохранёнными правками).
        if ($ctx && $ctx->isOpen() && $ctx->pageId === $pageId) {
            return ['page' => $ctx->page, 'blocks' => $ctx->blocks, 'live' => true];
        }

        $page = Page::find($pageId);

        if (! $page) {
            return ['error' => 'page_not_found'];
        }

        $blocks = PageBlock::where('page_id', $pageId)->with(['block:id,slug,name', 'block.blockActions:id,block_id', 'preset'])->orderBy('order')
            ->get()->map(function (PageBlock $pb) {
                $linked = $pb->preset_id && $pb->preset?->type === 'global';

                return [
                    'page_block_id' => $pb->id,
                    'block_slug' => $pb->block?->slug,
                    'order' => $pb->order,
                    // Что выводит блок: у связанного с глобальным пресетом — данные пресета
                    'data' => $linked ? ($pb->preset->data ?? []) : $pb->data,
                    'linked_preset' => $linked ? ['id' => $pb->preset->id, 'name' => $pb->preset->name] : null,
                    // Блок с action держать в кэше нельзя — агенту видно, что поправить
                    'cache_enabled' => (bool) $pb->cache_enabled,
                    'has_actions' => (bool) $pb->block?->blockActions->isNotEmpty(),
                ];
            })->all();

        return [
            'page' => ['id' => $page->id, 'title' => $page->title, 'url' => $page->url],
            'blocks' => $blocks,
            'live' => false,
        ];
    }
}
