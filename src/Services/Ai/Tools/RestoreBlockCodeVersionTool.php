<?php

namespace Templite\Cms\Services\Ai\Tools;

use Illuminate\Http\Request;
use Templite\Cms\Http\Controllers\Api\BlockCodeController;
use Templite\Cms\Models\BlockCodeVersion;
use Templite\Cms\Services\Ai\EditorContext;

class RestoreBlockCodeVersionTool implements Tool
{
    use ChecksCodePermission;

    public function name(): string
    {
        return 'restore_block_code_version';
    }

    public function definition(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'block_id' => ['type' => 'integer'],
                'version_id' => ['type' => 'integer', 'description' => 'ID версии из истории кода блока'],
            ],
            'required' => ['block_id', 'version_id'],
        ];
    }

    public function execute(array $args, ?EditorContext $ctx): array
    {
        $blockId = (int) ($args['block_id'] ?? 0);
        $versionId = (int) ($args['version_id'] ?? 0);

        if ($denied = $this->denyIfNoCodePermission()) {
            return $denied;
        }

        if (! BlockCodeVersion::where('block_id', $blockId)->whereKey($versionId)->exists()) {
            return ['error' => 'version_not_found'];
        }

        $request = Request::create("/api/cms/blocks/{$blockId}/code/versions/{$versionId}/restore", 'POST');
        $request->setUserResolver(fn () => $this->currentManager());

        try {
            $response = app(BlockCodeController::class)->restore($request, $blockId, $versionId);
        } catch (\Throwable $e) {
            return ['error' => 'restore_failed', 'message' => $e->getMessage()];
        }

        if (($response->getData(true)['success'] ?? false) !== true) {
            return ['error' => 'restore_failed'];
        }

        return [
            'status' => 'restored',
            'version_id' => $versionId,
            // Откат тоже меняет вёрстку на диске — перерисовываем превью.
            'editor_op' => ['op' => 'refresh_preview', 'payload' => ['block_id' => $blockId]],
        ];
    }
}
