<?php

namespace Templite\Cms\Services\Ai\Tools;

use Illuminate\Http\Request;
use Templite\Cms\Http\Controllers\Api\BlockPresetController;
use Templite\Cms\Models\BlockPreset;
use Templite\Cms\Services\Ai\EditorContext;
use Templite\Cms\Services\Ai\Presets\PresetInspector;

class UpdateBlockPresetTool implements Tool
{
    use ChecksPendingImages;

    public function __construct(private readonly PresetInspector $inspector)
    {
    }

    public function name(): string
    {
        return 'update_block_preset';
    }

    public function definition(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'preset_id' => ['type' => 'integer'],
                'name' => ['type' => 'string'],
                'description' => ['type' => 'string'],
                'is_default' => ['type' => 'boolean', 'description' => 'Сделать пресетом по умолчанию (только local)'],
                'data' => ['type' => 'object', 'description' => 'Данные пресета целиком — передавай все поля, не только изменённые'],
                'confirm' => ['type' => 'boolean', 'description' => 'true — только после явного согласия пользователя на правку глобального пресета на всех страницах из used_on'],
            ],
            'required' => ['preset_id'],
        ];
    }

    public function execute(array $args, ?EditorContext $ctx): array
    {
        $preset = BlockPreset::with('block')->find((int) ($args['preset_id'] ?? 0));

        if (! $preset) {
            return ['error' => 'preset_not_found'];
        }

        // null — не «стереть данные», а «данные не передавались»
        $hasData = isset($args['data']);
        $data = (array) ($args['data'] ?? []);

        if ($hasData) {
            if ($denied = $this->inspector->denyUnknownKeys($preset->block, $data)) {
                return $denied;
            }

            if ($denied = $this->denyIfPendingImages($data)) {
                return $denied;
            }
        }

        // Данные глобального пресета выводятся на всех связанных страницах сразу —
        // без согласия пользователя такую правку не пишем.
        $confirmed = filter_var($args['confirm'] ?? false, FILTER_VALIDATE_BOOLEAN);

        if ($hasData && $preset->type === 'global' && ! $confirmed) {
            $usedOn = $this->inspector->usedOn($preset);

            if ($usedOn !== []) {
                return [
                    'error' => 'confirmation_required',
                    'linked_count' => count($usedOn),
                    'used_on' => $usedOn,
                    'message' => 'Пресет глобальный: правка изменит блок на всех этих страницах. Покажи пользователю список и спроси согласия; после явного «да» повтори вызов с confirm: true. Ничего не записано.',
                ];
            }
        }

        $fields = array_intersect_key($args, array_flip(['name', 'description', 'is_default']));

        if ($hasData) {
            $fields['data'] = $data;
        }

        if ($fields === []) {
            return ['error' => 'nothing_to_update', 'message' => 'Передай хотя бы одно из: name, description, is_default, data.'];
        }

        $request = Request::create("/api/cms/block-presets/{$preset->id}", 'PUT', $fields);
        $request->setUserResolver(fn () => auth()->user());

        $payload = app(BlockPresetController::class)->update($request, $preset->id)->getData(true);

        if (($payload['success'] ?? false) !== true) {
            return ['error' => 'update_preset_failed', 'message' => $payload['message'] ?? null];
        }

        $result = ['status' => 'updated'];
        $fresh = $preset->fresh();

        if ($ctx && $ctx->isBlockEditorOpen((int) $preset->block_id)) {
            $result['editor_op'] = ['op' => 'refresh_presets', 'payload' => ['block_id' => (int) $preset->block_id]];
        } elseif ($preset->type === 'global' && $ctx && $ctx->isOpen()) {
            // Открытая страница держит пресет связанных блоков в сторе — подменяем и перерисовываем.
            // Шлём без сверки со снимком: блок мог быть привязан в этом же ходе, и снимок
            // этого ещё не знает. Мост сам отберёт связанные блоки.
            $result['editor_op'] = ['op' => 'refresh_linked_preset', 'payload' => ['preset' => $this->inspector->editorPayload($fresh)]];
        }

        return $result;
    }
}
