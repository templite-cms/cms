<?php

namespace Templite\Cms\Services\Ai\Tools;

use Illuminate\Http\Request;
use Templite\Cms\Http\Controllers\Api\PageBlockController;
use Templite\Cms\Models\BlockPreset;
use Templite\Cms\Services\Ai\EditorContext;

class AddBlockTool implements Tool
{
    public function name(): string
    {
        return 'add_block_to_page';
    }

    public function definition(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'page_id' => ['type' => 'integer'],
                'block_id' => ['type' => 'integer', 'description' => 'ID типа блока из list_block_types'],
                'data' => ['type' => 'object', 'description' => 'Контент блока по схеме полей'],
                'preset_id' => ['type' => 'integer', 'description' => 'Пресет этого блока из list_block_presets: local копирует данные, global связывает блок с пресетом. Не вместе с data'],
            ],
            'required' => ['page_id', 'block_id'],
        ];
    }

    public function execute(array $args, ?EditorContext $ctx): array
    {
        $pageId = (int) ($args['page_id'] ?? 0);
        $blockId = (int) ($args['block_id'] ?? 0);
        $data = (array) ($args['data'] ?? []);
        $presetId = isset($args['preset_id']) ? (int) $args['preset_id'] : null;

        if ($presetId !== null) {
            if ($data !== []) {
                return ['error' => 'ambiguous_source', 'message' => 'Передай либо data, либо preset_id — не оба.'];
            }

            $preset = BlockPreset::find($presetId);

            if (! $preset || (int) $preset->block_id !== $blockId) {
                return ['error' => 'preset_block_mismatch', 'message' => 'Пресет не принадлежит этому блоку — возьми id из list_block_presets.'];
            }
        }

        // Открытая в редакторе страница → предложение, не запись.
        if ($ctx && $ctx->isOpen() && $ctx->pageId === $pageId) {
            return [
                'status' => 'proposed_to_editor',
                'editor_op' => [
                    'op' => 'add_block',
                    'payload' => ['block_id' => $blockId, 'data' => $data, 'preset_id' => $presetId],
                ],
            ];
        }

        $params = ['block_id' => $blockId, 'data' => $data];

        if ($presetId !== null) {
            $params['preset_id'] = $presetId;
        }

        $request = Request::create("/api/cms/pages/{$pageId}/blocks", 'POST', $params);
        $request->setUserResolver(fn () => auth()->user());

        $response = app(PageBlockController::class)->store($request, $pageId);
        $payload = $response->getData(true);

        if (($payload['success'] ?? false) !== true) {
            return ['error' => 'add_block_failed', 'details' => $payload];
        }

        return [
            'page_block_id' => $payload['data']['id'] ?? null,
            'linked_preset' => ($payload['data']['preset']['type'] ?? null) === 'global'
                ? ['id' => $payload['data']['preset']['id'], 'name' => $payload['data']['preset']['name']]
                : null,
        ];
    }
}
