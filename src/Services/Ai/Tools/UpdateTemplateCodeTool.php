<?php

namespace Templite\Cms\Services\Ai\Tools;

use Illuminate\Http\Request;
use Templite\Cms\Http\Controllers\Api\TemplateCodeController;
use Templite\Cms\Models\TemplatePage;
use Templite\Cms\Services\Ai\EditorContext;

/**
 * Запись кода шаблона: Blade-обёртки страницы, SCSS и JS.
 *
 * Сам с файлами не работает: контроллер прогоняет Blade через валидатор
 * запрещённых конструкций, пишет файлы, сбрасывает кэш страниц этого шаблона
 * и пересобирает их ассеты.
 */
class UpdateTemplateCodeTool implements Tool
{
    use ChecksCodePermission;

    public function name(): string
    {
        return 'update_template_code';
    }

    public function definition(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'template_id' => ['type' => 'integer', 'description' => 'ID шаблона из list_templates'],
                'template' => ['type' => 'string', 'description' => 'Blade-обёртка страницы целиком, от <!DOCTYPE html>. Блоки подставляются директивой @yield(\'blocks\')'],
                'style' => ['type' => 'string', 'description' => 'SCSS шаблона. Пустая строка удаляет файл стилей'],
                'script' => ['type' => 'string', 'description' => 'JS шаблона. Пустая строка удаляет файл'],
            ],
            'required' => ['template_id'],
        ];
    }

    public function execute(array $args, ?EditorContext $ctx): array
    {
        $templateId = (int) ($args['template_id'] ?? 0);
        $template = TemplatePage::find($templateId);

        if (!$template) {
            return ['error' => 'template_not_found', 'template_id' => $templateId];
        }

        if ($denied = $this->denyIfNoCodePermission('templates.code')) {
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

        // Открыт редактор этого шаблона — правку отдаём в форму: сохранение
        // отправляет весь код из неё и затёрло бы запись на диске.
        if ($ctx && $ctx->isTemplateEditorOpen($templateId)) {
            return [
                'status' => 'proposed_to_editor',
                'editor_op' => [
                    'op' => 'update_template_code',
                    'payload' => array_merge(['template_id' => $templateId], $code),
                ],
            ];
        }

        $request = Request::create("/api/cms/templates/{$templateId}/code", 'PUT', $code);
        $request->setUserResolver(fn () => $this->currentManager());

        $response = app(TemplateCodeController::class)->update($request, $templateId);
        $payload = $response->getData(true);

        if (($payload['success'] ?? false) !== true) {
            // Отказ валидатора Blade приходит сообщением, а не исключением:
            // агент видит, какие конструкции запрещены, и правит код.
            return [
                'error' => 'validation_failed',
                'message' => $payload['message'] ?? null,
                // Раньше подсказка перечисляла @php, @include и @extends — и
                // сбивала с толку, когда отказ приходил на @push или @forelse:
                // агент не находил названных конструкций у себя и решал, что
                // сломана платформа. Про белый список сказать честнее.
                'hint' => 'Директивы Blade разрешены по белому списку, а не запрещены по чёрному: всё, чего нет в allowed_directives из get_template_guide, отклоняется. Сообщение выше называет конкретные конструкции — убери именно их.',
            ];
        }

        return [
            'status' => 'saved',
            'template_id' => $templateId,
            'saved_parts' => array_keys($code),
        ];
    }
}
