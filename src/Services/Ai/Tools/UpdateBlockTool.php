<?php

namespace Templite\Cms\Services\Ai\Tools;

use Illuminate\Http\Request;
use Templite\Cms\Http\Controllers\Api\PageBlockController;
use Templite\Cms\Models\PageBlock;
use Templite\Cms\Services\Ai\EditorContext;

class UpdateBlockTool implements Tool
{
    use ChecksPendingImages;

    public function name(): string
    {
        return 'update_block_data';
    }

    public function definition(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'page_block_id' => ['type' => 'integer'],
                'data' => ['type' => 'object', 'description' => 'Новые данные блока'],
            ],
            'required' => ['page_block_id', 'data'],
        ];
    }

    public function execute(array $args, ?EditorContext $ctx): array
    {
        $pageBlockId = (int) ($args['page_block_id'] ?? 0);
        $data = (array) ($args['data'] ?? []);

        $pb = PageBlock::find($pageBlockId);

        if (! $pb) {
            return ['error' => 'page_block_not_found'];
        }

        // Связанный с глобальным пресетом блок выводит данные пресета: запись в его
        // data ничего не изменила бы на сайте, а ответ «updated» ввёл бы в заблуждение.
        if ($pb->preset_id && $pb->preset?->type === 'global') {
            return [
                'error' => 'linked_to_global_preset',
                'preset' => ['id' => $pb->preset->id, 'name' => $pb->preset->name],
                'message' => 'Блок связан с глобальным пресетом и выводит его данные. Два пути: изменить сам пресет через update_block_preset (правка затронет все страницы из get_block_preset.used_on — нужно согласие пользователя) или отвязать блок через apply_preset_to_block с unlink: true и затем править только его.',
            ];
        }

        if ($denied = $this->denyIfPendingImages($data)) {
            return $denied;
        }

        if ($ctx && $ctx->isOpen() && $pb->page_id === $ctx->pageId) {
            return [
                'status' => 'proposed_to_editor',
                'editor_op' => [
                    'op' => 'update_block',
                    'payload' => ['page_block_id' => $pageBlockId, 'data' => $data],
                ],
            ];
        }

        $request = Request::create("/api/cms/page-blocks/{$pageBlockId}", 'PUT', ['data' => $data]);
        $request->setUserResolver(fn () => auth()->user());

        $response = app(PageBlockController::class)->update($request, $pageBlockId);
        $payload = $response->getData(true);

        return ['status' => ($payload['success'] ?? false) ? 'updated' : 'failed'];
    }
}
