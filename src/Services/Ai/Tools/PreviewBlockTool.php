<?php

namespace Templite\Cms\Services\Ai\Tools;

use Illuminate\Http\Request;
use Templite\Cms\Http\Controllers\Api\BlockController;
use Templite\Cms\Models\Block;
use Templite\Cms\Services\Ai\EditorContext;

class PreviewBlockTool implements Tool
{
    use ChecksCodePermission;

    /** Превью нужно агенту для проверки, а не для чтения целиком. */
    private const HTML_LIMIT = 4000;

    public function name(): string
    {
        return 'preview_block';
    }

    public function definition(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'block_id' => ['type' => 'integer'],
                'template' => ['type' => 'string', 'description' => 'Blade-шаблон черновика; без него берётся сохранённый'],
                'style' => ['type' => 'string', 'description' => 'SCSS черновика'],
                'script' => ['type' => 'string', 'description' => 'JS черновика'],
            ],
            'required' => ['block_id'],
        ];
    }

    public function execute(array $args, ?EditorContext $ctx): array
    {
        if ($denied = $this->denyDraftWithoutCodePermission($args, 'blocks.code')) {
            return $denied;
        }

        $blockId = (int) ($args['block_id'] ?? 0);

        if (! Block::whereKey($blockId)->exists()) {
            return ['error' => 'block_not_found'];
        }

        $payload = array_filter([
            'template' => $args['template'] ?? null,
            'style' => $args['style'] ?? null,
            'script' => $args['script'] ?? null,
        ], fn ($v) => $v !== null);

        $request = Request::create("/api/cms/blocks/{$blockId}/preview", 'POST', $payload);
        $request->setUserResolver(fn () => auth()->user());

        try {
            $response = app(BlockController::class)->preview($request, $blockId);
        } catch (\Throwable $e) {
            // Небезопасный шаблон валидатор отклоняет исключением ещё до рендера.
            return ['ok' => false, 'error' => 'unsafe_or_failed', 'message' => $e->getMessage()];
        }

        $html = (string) ($response->getData(true)['data']['html'] ?? '');

        // Ошибку шаблона превью возвращает не исключением, а разметкой с этой пометкой.
        if (str_contains($html, 'Template Error:')) {
            return [
                'ok' => false,
                'error' => 'template_error',
                'message' => $this->extractError($html),
            ];
        }

        return [
            'ok' => true,
            'html' => mb_substr($html, 0, self::HTML_LIMIT),
            'truncated' => mb_strlen($html) > self::HTML_LIMIT,
        ];
    }

    /** Вытащить текст ошибки из HTML-заглушки превью. */
    private function extractError(string $html): string
    {
        if (preg_match('/<strong>Template Error:<\/strong><br>(.*?)<\/div>/s', $html, $m)) {
            return trim(html_entity_decode(strip_tags($m[1])));
        }

        return 'Ошибка рендера шаблона';
    }
}
