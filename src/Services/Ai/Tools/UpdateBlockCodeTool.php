<?php

namespace Templite\Cms\Services\Ai\Tools;

use Illuminate\Http\Request;
use Templite\Cms\Http\Controllers\Api\BlockCodeController;
use Templite\Cms\Models\Block;
use Templite\Cms\Services\Ai\EditorContext;

class UpdateBlockCodeTool implements Tool
{
    use ChecksCodePermission;

    public function name(): string
    {
        return 'update_block_code';
    }

    public function definition(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'block_id' => ['type' => 'integer'],
                'template' => ['type' => 'string', 'description' => 'Blade-шаблон блока'],
                'style' => ['type' => 'string', 'description' => 'SCSS блока'],
                'script' => ['type' => 'string', 'description' => 'JS блока'],
            ],
            'required' => ['block_id'],
        ];
    }

    public function execute(array $args, ?EditorContext $ctx): array
    {
        $blockId = (int) ($args['block_id'] ?? 0);

        if (! Block::whereKey($blockId)->exists()) {
            return ['error' => 'block_not_found'];
        }

        if ($denied = $this->denyIfNoCodePermission()) {
            return $denied;
        }

        $code = array_filter([
            'template' => $args['template'] ?? null,
            'style' => $args['style'] ?? null,
            'script' => $args['script'] ?? null,
        ], fn ($v) => $v !== null);

        if ($code === []) {
            return ['error' => 'nothing_to_update'];
        }

        // Открыт редактор этого блока — отдаём правку на ревью, а не пишем на диск.
        if ($ctx && $ctx->isBlockEditorOpen($blockId)) {
            return [
                'status' => 'proposed_to_editor',
                'editor_op' => [
                    'op' => 'update_block_code',
                    'payload' => array_merge(['block_id' => $blockId], $code),
                ],
            ];
        }

        $request = Request::create("/api/cms/blocks/{$blockId}/code", 'PUT', $code + ['version_source' => 'ai']);
        $request->setUserResolver(fn () => $this->currentManager());

        try {
            $response = app(BlockCodeController::class)->update($request, $blockId);
        } catch (\Throwable $e) {
            return ['error' => 'save_failed', 'message' => $e->getMessage()];
        }

        $payload = $response->getData(true);

        if (($payload['success'] ?? false) !== true) {
            // Сюда попадает и отказ валидатора Blade — агент увидит, что именно запрещено.
            return ['error' => 'save_failed', 'message' => $payload['message'] ?? null];
        }

        return [
            'status' => 'saved',
            'block_id' => $blockId,
            // Код сохранён, но может вести себя неожиданно (стили без префикса slug,
            // несколько корней, поиск по document) — покажи пользователю или поправь
            'warnings' => $payload['data']['warnings'] ?? [],
            // Вёрстка на диске изменилась — просим фронт перерисовать превью:
            // iframe сам о смене кода не узнает.
            'editor_op' => ['op' => 'refresh_preview', 'payload' => ['block_id' => $blockId]],
        ];
    }
}
