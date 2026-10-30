<?php

namespace Templite\Cms\Services\Ai\Tools;

use Illuminate\Http\Request;
use Templite\Cms\Http\Controllers\Api\TemplateController;
use Templite\Cms\Services\Ai\EditorContext;

/**
 * Создание шаблона страницы: запись в базе и, если передан код, сразу файлы.
 *
 * Как и у actions, код идёт вторым вызовом: POST /templates принимает только
 * название и slug, а вёрстку пишет отдельный контроллер кода.
 */
class CreateTemplateTool implements Tool
{
    use ChecksCodePermission;

    public function name(): string
    {
        return 'create_template';
    }

    public function definition(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'name' => ['type' => 'string', 'description' => 'Название для админки'],
                'slug' => ['type' => 'string', 'description' => 'Ключ латиницей через дефис — по нему называется каталог файлов шаблона'],
                'template' => ['type' => 'string', 'description' => 'Blade-обёртка страницы целиком, от <!DOCTYPE html>. Блоки страницы подставляются директивой @yield(\'blocks\') — без неё страница отрендерится пустой'],
                'style' => ['type' => 'string', 'description' => 'SCSS шаблона: общие стили сайта'],
                'script' => ['type' => 'string', 'description' => 'JS шаблона'],
            ],
            'required' => ['name', 'slug'],
        ];
    }

    public function execute(array $args, ?EditorContext $ctx): array
    {
        if ($denied = $this->denyIfNoCodePermission('templates.code')) {
            return $denied;
        }

        $request = Request::create('/api/cms/templates', 'POST', [
            'name' => (string) ($args['name'] ?? ''),
            'slug' => (string) ($args['slug'] ?? ''),
        ]);
        $request->setUserResolver(fn () => $this->currentManager());

        // Занятый slug прилетит ValidationException — ToolRegistry отдаст его
        // агенту как validation_failed с полями.
        $response = app(TemplateController::class)->store($request);
        $created = $response->getData(true);

        if (($created['success'] ?? false) !== true) {
            return ['error' => 'create_failed', 'message' => $created['message'] ?? null];
        }

        $templateId = (int) ($created['data']['id'] ?? 0);
        $code = array_filter([
            'template' => $args['template'] ?? null,
            'style' => $args['style'] ?? null,
            'script' => $args['script'] ?? null,
        ], fn ($value) => is_string($value) && $value !== '');

        if ($code === []) {
            return [
                'status' => 'created',
                'template_id' => $templateId,
                'slug' => $created['data']['slug'] ?? null,
                'note' => 'Кода у шаблона пока нет — контроллер положил заготовку из stubs. Замени её через update_template_code.',
            ];
        }

        $saved = app(UpdateTemplateCodeTool::class)->execute(
            ['template_id' => $templateId] + $code,
            $ctx,
        );

        if (isset($saved['error'])) {
            return $saved + [
                'status' => 'created_without_code',
                'template_id' => $templateId,
                'next_step' => 'Шаблон уже создан. Исправь код и сохрани его через update_template_code с этим template_id — повторный create_template упрётся в занятый slug.',
            ];
        }

        return ['status' => 'created', 'template_id' => $templateId] + $saved;
    }
}
