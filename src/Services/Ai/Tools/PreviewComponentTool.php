<?php

namespace Templite\Cms\Services\Ai\Tools;

use Illuminate\Http\Request;
use Templite\Cms\Http\Controllers\Api\ComponentController;
use Templite\Cms\Models\Component;
use Templite\Cms\Services\Ai\EditorContext;

/**
 * Рендер компонента без сохранения — как preview_block для блока.
 * Ошибка Blade возвращается текстом, файлы на диске не трогаются.
 */
class PreviewComponentTool implements Tool
{
    use ChecksCodePermission;

    private const HTML_LIMIT = 4000;

    public function name(): string
    {
        return 'preview_component';
    }

    public function definition(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'component_id' => ['type' => 'integer', 'description' => 'ID компонента из list_components'],
                'template' => ['type' => 'string', 'description' => 'Blade-черновик. Без него берётся сохранённая разметка'],
                'style' => ['type' => 'string', 'description' => 'SCSS-черновик'],
                'script' => ['type' => 'string', 'description' => 'JS-черновик'],
            ],
            'required' => ['component_id'],
        ];
    }

    public function execute(array $args, ?EditorContext $ctx): array
    {
        if ($denied = $this->denyDraftWithoutCodePermission($args, 'components.code')) {
            return $denied;
        }

        $componentId = (int) ($args['component_id'] ?? 0);

        if (!Component::whereKey($componentId)->exists()) {
            return ['error' => 'component_not_found', 'component_id' => $componentId];
        }

        // Контроллер ждёт черновики в полях с суффиксом _code — так их шлёт
        // редактор компонента.
        $payload = array_filter([
            'template_code' => $args['template'] ?? null,
            'style_code' => $args['style'] ?? null,
            'script_code' => $args['script'] ?? null,
        ], fn ($value) => $value !== null);

        $request = Request::create("/api/cms/components/{$componentId}/preview", 'POST', $payload);
        $request->setUserResolver(fn () => auth()->user());

        try {
            $response = app(ComponentController::class)->preview($request, $componentId);
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => 'render_failed', 'message' => $e->getMessage()];
        }

        $html = (string) ($response->getData(true)['data']['html'] ?? '');

        if (str_contains($html, 'Template Error:') || str_contains($html, 'Component Error:')) {
            return ['ok' => false, 'error' => 'template_error', 'message' => $this->extractError($html)];
        }

        return array_filter([
            'ok' => true,
            'html' => mb_substr($html, 0, self::HTML_LIMIT),
            'truncated' => mb_strlen($html) > self::HTML_LIMIT ?: null,
        ], fn ($value) => $value !== null);
    }

    private function extractError(string $html): string
    {
        if (preg_match('/<strong>(?:Template|Component) Error:<\/strong><br>(.*?)<\/div>/s', $html, $m)) {
            return trim(strip_tags($m[1]));
        }

        return 'Компонент не отрендерился.';
    }
}
