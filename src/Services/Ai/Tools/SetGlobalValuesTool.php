<?php

namespace Templite\Cms\Services\Ai\Tools;

use Illuminate\Http\Request;
use Templite\Cms\Http\Controllers\Api\GlobalSettingsController;
use Templite\Cms\Services\Ai\EditorContext;
use Templite\Cms\Services\Ai\Settings\GlobalValueWriter;

/**
 * Заполнение глобальных полей значениями.
 *
 * При открытом экране значений запись уходит не в базу, а в форму: экран
 * /cms/settings сохраняет все values разом, и правка мимо формы прожила бы
 * ровно до нажатия пользователем «Сохранить».
 */
class SetGlobalValuesTool implements Tool
{
    use ChecksSettingsPermission;

    public function __construct(private readonly GlobalValueWriter $writer)
    {
    }

    public function name(): string
    {
        return 'set_global_values';
    }

    public function definition(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'values' => [
                    'type' => 'object',
                    'description' => 'Ключ поля => значение. Скаляр строкой, link объектом {url, target}, повторитель списком объектов.',
                    'additionalProperties' => true,
                ],
            ],
            'required' => ['values'],
        ];
    }

    public function execute(array $args, ?EditorContext $ctx): array
    {
        if ($deny = $this->denyIfNoSettingsPermission('settings.edit')) {
            return $deny;
        }

        $values = $args['values'] ?? null;

        if (! is_array($values) || $values === []) {
            return ['error' => 'validation_failed', 'details' => ['values' => ['Передай хотя бы одно значение.']]];
        }

        if ($errors = $this->writer->validate($values)) {
            return ['error' => 'validation_failed', 'details' => $errors];
        }

        if ($ctx && $ctx->isSettingsEditorOpen('values')) {
            return [
                'status' => 'proposed_to_editor',
                'keys' => array_keys($values),
                'editor_op' => ['op' => 'set_global_values', 'payload' => ['values' => $values]],
            ];
        }

        $request = Request::create('/api/cms/settings/values', 'PUT', ['values' => $values]);
        $request->setUserResolver(fn () => auth()->user());

        $response = app(GlobalSettingsController::class)->saveValues($request);
        $payload = $response->getData(true);

        // Как у соседних инструментов, которые тоже проксируют контроллер
        // через Request::create() (UpdateBlockCodeTool, UpdateActionCodeTool
        // и др.): при неуспехе агент получает причину, а не голый 'failed'.
        if (($payload['success'] ?? false) !== true) {
            return ['error' => 'save_failed', 'message' => $payload['message'] ?? null];
        }

        return [
            'status' => 'saved',
            'keys' => array_keys($values),
        ];
    }
}
