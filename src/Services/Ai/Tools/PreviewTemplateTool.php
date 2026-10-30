<?php

namespace Templite\Cms\Services\Ai\Tools;

use Illuminate\Http\Request;
use Templite\Cms\Http\Controllers\Api\TemplateCodeController;
use Templite\Cms\Models\TemplatePage;
use Templite\Cms\Services\Ai\EditorContext;

/**
 * Рендер кода шаблона без сохранения — то же, что preview_block для вёрстки
 * блока. Ошибка Blade возвращается текстом, а не ломает файлы на диске.
 */
class PreviewTemplateTool implements Tool
{
    use ChecksCodePermission;

    /** Сколько разметки отдаём агенту: шаблон целиком вытеснил бы диалог из контекста. */
    private const HTML_LIMIT = 4000;

    public function name(): string
    {
        return 'preview_template';
    }

    public function definition(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'template_id' => ['type' => 'integer', 'description' => 'ID шаблона из list_templates'],
                'template' => ['type' => 'string', 'description' => 'Blade-черновик. Без него берётся сохранённый код'],
                'style' => ['type' => 'string', 'description' => 'SCSS-черновик'],
                'script' => ['type' => 'string', 'description' => 'JS-черновик'],
            ],
            'required' => ['template_id'],
        ];
    }

    public function execute(array $args, ?EditorContext $ctx): array
    {
        if ($denied = $this->denyDraftWithoutCodePermission($args, 'templates.code')) {
            return $denied;
        }

        $templateId = (int) ($args['template_id'] ?? 0);

        if (!TemplatePage::whereKey($templateId)->exists()) {
            return ['error' => 'template_not_found', 'template_id' => $templateId];
        }

        // Контроллер ждёт черновики в полях с суффиксом _code — так их шлёт
        // редактор шаблона; имена аргументов инструмента короче, как у блоков.
        $payload = array_filter([
            'template_code' => $args['template'] ?? null,
            'style_code' => $args['style'] ?? null,
            'script_code' => $args['script'] ?? null,
        ], fn ($value) => $value !== null);

        $request = Request::create("/api/cms/templates/{$templateId}/preview", 'POST', $payload);
        $request->setUserResolver(fn () => auth()->user());

        try {
            $response = app(TemplateCodeController::class)->preview($request, $templateId);
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => 'render_failed', 'message' => $e->getMessage()];
        }

        $html = (string) ($response->getData(true)['data']['html'] ?? '');

        if (str_contains($html, 'Template Error:')) {
            return ['ok' => false, 'error' => 'template_error', 'message' => $this->extractError($html)];
        }

        return array_filter([
            'ok' => true,
            'html' => mb_substr($html, 0, self::HTML_LIMIT),
            'truncated' => mb_strlen($html) > self::HTML_LIMIT ?: null,
        ], fn ($value) => $value !== null);
    }

    /** Текст ошибки из HTML-заглушки превью. */
    private function extractError(string $html): string
    {
        if (preg_match('/<strong>Template Error:<\/strong><br>(.*?)<\/div>/s', $html, $m)) {
            return trim(strip_tags($m[1]));
        }

        return 'Шаблон не отрендерился.';
    }
}
