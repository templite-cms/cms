<?php

namespace Templite\Cms\Services\Ai\Tools;

use Illuminate\Http\Request;
use Templite\Cms\Http\Controllers\Api\PageBlockController;
use Templite\Cms\Models\BlockPreset;
use Templite\Cms\Models\PageBlock;
use Templite\Cms\Services\Ai\EditorContext;
use Templite\Cms\Services\Ai\Presets\PresetInspector;

/**
 * Три действия с пресетом у блока на странице — те же, что в редакторе страницы:
 * применить локальный (скопировать данные), привязать глобальный, отвязать.
 */
class ApplyPresetToBlockTool implements Tool
{
    public function __construct(private readonly PresetInspector $inspector)
    {
    }

    public function name(): string
    {
        return 'apply_preset_to_block';
    }

    public function definition(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'page_block_id' => ['type' => 'integer'],
                'preset_id' => ['type' => 'integer', 'description' => 'Пресет этого блока: local — данные копируются в блок (связь снимается), global — блок связывается с пресетом. Не вместе с unlink'],
                'unlink' => ['type' => 'boolean', 'description' => 'true — отвязать блок от глобального пресета: данные пресета станут собственными данными блока. Не вместе с preset_id'],
            ],
            'required' => ['page_block_id'],
        ];
    }

    public function execute(array $args, ?EditorContext $ctx): array
    {
        $pb = PageBlock::with('preset')->find((int) ($args['page_block_id'] ?? 0));

        if (! $pb) {
            return ['error' => 'page_block_not_found'];
        }

        $unlink = filter_var($args['unlink'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $presetId = isset($args['preset_id']) ? (int) $args['preset_id'] : null;

        if ($unlink === ($presetId !== null)) {
            return ['error' => 'ambiguous_target', 'message' => 'Передай либо preset_id, либо unlink: true.'];
        }

        $editorOpen = $ctx && $ctx->isOpen() && $ctx->pageId === (int) $pb->page_id;

        if ($unlink) {
            if (! $pb->preset_id || $pb->preset?->type !== 'global') {
                return ['error' => 'not_linked', 'message' => 'Блок не связан с глобальным пресетом — отвязывать нечего.'];
            }

            if ($editorOpen) {
                return $this->toEditor('unlink_preset', ['page_block_id' => (int) $pb->id]);
            }

            return $this->put($pb, ['preset_id' => null, 'data' => $pb->preset->data ?? []], 'unlinked');
        }

        $preset = BlockPreset::find($presetId);

        if (! $preset || (int) $preset->block_id !== (int) $pb->block_id) {
            return ['error' => 'preset_block_mismatch', 'message' => 'Пресет не принадлежит блоку этого экземпляра — возьми id из list_block_presets.'];
        }

        $global = $preset->type === 'global';

        if ($editorOpen) {
            return $this->toEditor($global ? 'link_preset' : 'apply_preset', [
                'page_block_id' => (int) $pb->id,
                'preset' => $this->inspector->editorPayload($preset),
            ]);
        }

        return $global
            ? $this->put($pb, ['preset_id' => $preset->id], 'linked')
            : $this->put($pb, ['preset_id' => null, 'data' => $preset->data ?? []], 'applied');
    }

    /** @param array<string,mixed> $payload */
    private function toEditor(string $op, array $payload): array
    {
        return ['status' => 'proposed_to_editor', 'editor_op' => ['op' => $op, 'payload' => $payload]];
    }

    /** @param array<string,mixed> $params */
    private function put(PageBlock $pb, array $params, string $status): array
    {
        $request = Request::create("/api/cms/page-blocks/{$pb->id}", 'PUT', $params);
        $request->setUserResolver(fn () => auth()->user());

        $payload = app(PageBlockController::class)->update($request, $pb->id)->getData(true);

        return ($payload['success'] ?? false) === true
            ? ['status' => $status]
            : ['error' => 'apply_preset_failed', 'message' => $payload['message'] ?? null];
    }
}
