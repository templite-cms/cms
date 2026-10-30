<?php

namespace Templite\Cms\Services\Ai\Tools;

use Illuminate\Http\Request;
use Templite\Cms\Http\Controllers\Api\BlockPresetController;
use Templite\Cms\Models\Block;
use Templite\Cms\Services\Ai\EditorContext;
use Templite\Cms\Services\Ai\Presets\PresetInspector;

class CreateBlockPresetTool implements Tool
{
    use ChecksPendingImages;

    public function __construct(private readonly PresetInspector $inspector)
    {
    }

    public function name(): string
    {
        return 'create_block_preset';
    }

    public function definition(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'block_id' => ['type' => 'integer', 'description' => 'ID типа блока'],
                'name' => ['type' => 'string', 'description' => 'Название пресета — пользователь видит его в каталоге блоков'],
                'type' => [
                    'type' => 'string',
                    'enum' => ['local', 'global'],
                    'description' => 'local — шаблон: данные копируются в блок при добавлении, связи не остаётся. global — связь: связанные блоки выводят данные пресета, его правка меняет их на всех страницах',
                ],
                'is_default' => ['type' => 'boolean', 'description' => 'Пресет по умолчанию: им заполняется блок, добавленный без данных. Только для local'],
                'description' => ['type' => 'string'],
                'data' => ['type' => 'object', 'description' => 'Данные по схеме полей блока (ключи корневых полей из get_block_code). Не вместе с from_page_block_id'],
                'from_page_block_id' => ['type' => 'integer', 'description' => 'Взять данные из блока на странице (page_block_id из get_page). Не вместе с data'],
            ],
            'required' => ['block_id', 'name', 'type'],
        ];
    }

    public function execute(array $args, ?EditorContext $ctx): array
    {
        $block = Block::find((int) ($args['block_id'] ?? 0));

        if (! $block) {
            return ['error' => 'block_not_found'];
        }

        $fromId = $args['from_page_block_id'] ?? null;

        if (array_key_exists('data', $args) && $fromId !== null) {
            return ['error' => 'ambiguous_source', 'message' => 'Передай либо data, либо from_page_block_id — не оба.'];
        }

        if ($fromId !== null) {
            $source = $this->inspector->pageBlockData((int) $fromId, $block->id, $ctx);

            if (isset($source['error'])) {
                return $source;
            }

            // В данных экземпляра могут остаться ключи удалённых полей — агент их не
            // исправит, поэтому отбрасываем, а не отказываем.
            $allowed = $block->fields()->whereNull('parent_id')->pluck('key')->all();
            $data = array_intersect_key($source['data'], array_flip($allowed));
        } else {
            $data = (array) ($args['data'] ?? []);
        }

        if ($denied = $this->inspector->denyUnknownKeys($block, $data)) {
            return $denied;
        }

        if ($denied = $this->denyIfPendingImages($data)) {
            return $denied;
        }

        $name = (string) ($args['name'] ?? '');
        $slug = $this->inspector->uniqueSlug($block, $name);

        $request = Request::create('/api/cms/block-presets', 'POST', [
            'name' => $name,
            'slug' => $slug,
            'block_id' => $block->id,
            'type' => (string) ($args['type'] ?? ''),
            'is_default' => filter_var($args['is_default'] ?? false, FILTER_VALIDATE_BOOLEAN),
            'description' => $args['description'] ?? null,
            'data' => $data,
        ]);
        $request->setUserResolver(fn () => auth()->user());

        $payload = app(BlockPresetController::class)->store($request)->getData(true);

        if (($payload['success'] ?? false) !== true) {
            return ['error' => 'create_preset_failed', 'message' => $payload['message'] ?? null];
        }

        $result = [
            'preset_id' => $payload['data']['id'] ?? null,
            'slug' => $slug,
            'type' => $payload['data']['type'] ?? null,
            'is_default' => (bool) ($payload['data']['is_default'] ?? false),
        ];

        // Открытый редактор этого блока показывает список пресетов в превью и в панели.
        if ($ctx && $ctx->isBlockEditorOpen($block->id)) {
            $result['editor_op'] = ['op' => 'refresh_presets', 'payload' => ['block_id' => $block->id]];
        }

        return $result;
    }
}
