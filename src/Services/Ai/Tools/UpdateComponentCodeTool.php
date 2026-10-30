<?php

namespace Templite\Cms\Services\Ai\Tools;

use Illuminate\Http\Request;
use Templite\Cms\Http\Controllers\Api\ComponentCodeController;
use Templite\Cms\Models\Component;
use Templite\Cms\Services\Ai\EditorContext;

/**
 * Запись кода Blade-компонента: разметки, стилей и скрипта.
 *
 * Контроллер прогоняет Blade через валидатор запрещённых конструкций и
 * раскладывает файлы по каталогу компонента в storage.
 */
class UpdateComponentCodeTool implements Tool
{
    use ChecksCodePermission;

    public function name(): string
    {
        return 'update_component_code';
    }

    public function definition(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'component_id' => ['type' => 'integer', 'description' => 'ID компонента из list_components'],
                'template' => ['type' => 'string', 'description' => 'Blade-разметка компонента. Параметры приходят переменными ($title, $items); @props запрещена, значения по умолчанию задавай через {{ $title ?? \'запасной текст\' }}'],
                'style' => ['type' => 'string', 'description' => 'SCSS компонента. Подключится сам везде, где компонент используется'],
                'script' => ['type' => 'string', 'description' => 'JS компонента'],
            ],
            'required' => ['component_id'],
        ];
    }

    public function execute(array $args, ?EditorContext $ctx): array
    {
        $componentId = (int) ($args['component_id'] ?? 0);
        $component = Component::find($componentId);

        if (!$component) {
            return ['error' => 'component_not_found', 'component_id' => $componentId];
        }

        if ($denied = $this->denyIfNoCodePermission('components.code')) {
            return $denied;
        }

        $code = array_filter([
            'template' => $args['template'] ?? null,
            'style' => $args['style'] ?? null,
            'script' => $args['script'] ?? null,
        ], fn ($value) => $value !== null);

        if ($code === []) {
            return ['error' => 'nothing_to_update', 'message' => 'Передай хотя бы одно из полей: template, style, script.'];
        }

        $request = Request::create("/api/cms/components/{$componentId}/code", 'PUT', $code);
        $request->setUserResolver(fn () => $this->currentManager());

        $response = app(ComponentCodeController::class)->update($request, $componentId);
        $payload = $response->getData(true);

        if (($payload['success'] ?? false) !== true) {
            return [
                'error' => 'validation_failed',
                'message' => $payload['message'] ?? null,
                'hint' => 'Директивы Blade разрешены по белому списку, а не запрещены по чёрному: всё, чего нет в allowed_directives из get_component_guide, отклоняется — в том числе @props. Сообщение выше называет конкретные конструкции.',
            ];
        }

        return [
            'status' => 'saved',
            'component_id' => $componentId,
            'slug' => $component->slug,
            'saved_parts' => array_keys($code),
        ];
    }
}
