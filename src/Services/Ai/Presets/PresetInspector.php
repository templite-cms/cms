<?php

namespace Templite\Cms\Services\Ai\Presets;

use Illuminate\Support\Str;
use Templite\Cms\Models\Block;
use Templite\Cms\Models\BlockPreset;
use Templite\Cms\Models\PageBlock;
use Templite\Cms\Services\Ai\EditorContext;

/**
 * Общая логика инструментов ассистента для пресетов блоков.
 */
class PresetInspector
{
    /**
     * Страницы, где блоки связаны с глобальным пресетом.
     * У локального пресета связей нет: его данные копируются в блок.
     *
     * @return array<int,array{page_id:int,title:?string,url:?string,page_block_id:int}>
     */
    public function usedOn(BlockPreset $preset): array
    {
        if ($preset->type !== 'global') {
            return [];
        }

        return PageBlock::where('preset_id', $preset->id)
            ->with('page:id,title,url')
            ->orderBy('page_id')
            ->get()
            ->map(fn (PageBlock $pb) => [
                'page_id' => (int) $pb->page_id,
                'title' => $pb->page?->title,
                'url' => $pb->page?->url,
                'page_block_id' => (int) $pb->id,
            ])
            ->all();
    }

    /**
     * Данные, которые выводит экземпляр блока. Открытая в редакторе страница
     * отдаёт живые данные с несохранёнными правками; связанный с глобальным
     * пресетом экземпляр — данные пресета.
     *
     * @return array{data:array<string,mixed>}|array{error:string,message?:string}
     */
    public function pageBlockData(int $pageBlockId, int $blockId, ?EditorContext $ctx): array
    {
        $pb = PageBlock::with('preset')->find($pageBlockId);

        if (! $pb) {
            return ['error' => 'page_block_not_found'];
        }

        if ((int) $pb->block_id !== $blockId) {
            return [
                'error' => 'block_mismatch',
                'message' => "Блок на странице — другого типа (block_id {$pb->block_id}), его данные к этому блоку не подходят.",
            ];
        }

        if ($ctx && $ctx->isOpen() && $ctx->pageId === (int) $pb->page_id) {
            foreach ($ctx->blocks as $live) {
                if ((int) ($live['page_block_id'] ?? 0) === (int) $pb->id) {
                    return ['data' => (array) ($live['data'] ?? [])];
                }
            }
        }

        $linked = $pb->preset_id && $pb->preset?->type === 'global';

        return ['data' => $linked ? ($pb->preset->data ?? []) : ($pb->data ?? [])];
    }

    /**
     * Ключи данных, которых нет среди корневых полей блока, — по ним ничего не выведется.
     *
     * @param  array<string,mixed>  $data
     * @return array<string,mixed>|null отказ для агента либо null
     */
    public function denyUnknownKeys(Block $block, array $data): ?array
    {
        $allowed = $block->fields()->whereNull('parent_id')->pluck('key')->all();
        $unknown = array_values(array_diff(array_map('strval', array_keys($data)), $allowed));

        if ($unknown === []) {
            return null;
        }

        return [
            'error' => 'unknown_keys',
            'unknown' => $unknown,
            'allowed' => $allowed,
            'message' => 'Этих ключей нет среди полей блока — данные по ним не выведутся. Ключи полей повторителя кладутся внутрь элементов его array-поля.',
        ];
    }

    /** Уникальный slug пресета: slug блока + транслит названия. */
    public function uniqueSlug(Block $block, string $name): string
    {
        // Запас под суффикс -N: колонка slug — 255 символов
        $base = trim(substr($block->slug . '-' . Str::slug($name), 0, 240), '-');
        $slug = $base;
        $n = 2;

        while (BlockPreset::where('slug', $slug)->exists()) {
            $slug = $base . '-' . $n++;
        }

        return $slug;
    }

    /**
     * Пресет в той форме, в которой его держит стор редактора страницы
     * (совпадает с `preset` в PageBlockResource).
     *
     * @return array{id:int,name:string,type:string,data:array<string,mixed>}
     */
    public function editorPayload(BlockPreset $preset): array
    {
        return [
            'id' => (int) $preset->id,
            'name' => $preset->name,
            'type' => $preset->type,
            'data' => $preset->data ?? [],
        ];
    }
}
