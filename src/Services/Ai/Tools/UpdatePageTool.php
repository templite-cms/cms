<?php

namespace Templite\Cms\Services\Ai\Tools;

use Illuminate\Http\Request;
use Templite\Cms\Http\Controllers\Api\PageController;
use Templite\Cms\Models\Page;
use Templite\Cms\Models\TemplatePage;
use Templite\Cms\Services\Ai\EditorContext;

/**
 * Правка самой страницы: заголовок, адрес, шаблон, публикация.
 *
 * Блоки страницы правят другие инструменты — здесь только свойства страницы.
 */
class UpdatePageTool implements Tool
{
    public function name(): string
    {
        return 'update_page';
    }

    public function definition(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'page_id' => ['type' => 'integer', 'description' => 'ID страницы из list_pages'],
                'title' => ['type' => 'string', 'description' => 'Заголовок страницы'],
                'alias' => ['type' => 'string', 'description' => 'ЧПУ-сегмент латиницей. Меняет адрес страницы — старые ссылки перестанут работать'],
                'template_id' => ['type' => 'integer', 'description' => 'ID шаблона из list_templates. 0 — отвязать шаблон'],
                'status' => ['type' => 'integer', 'enum' => [0, 1], 'description' => '1 — опубликована, 0 — скрыта'],
            ],
            'required' => ['page_id'],
        ];
    }

    public function execute(array $args, ?EditorContext $ctx): array
    {
        $pageId = (int) ($args['page_id'] ?? 0);
        $page = Page::find($pageId);

        if (!$page) {
            return ['error' => 'page_not_found', 'page_id' => $pageId];
        }

        $payload = [];

        foreach (['title', 'alias'] as $key) {
            if (isset($args[$key]) && is_string($args[$key])) {
                $payload[$key] = $args[$key];
            }
        }

        if (isset($args['status'])) {
            $payload['status'] = (int) $args['status'] ? 1 : 0;
        }

        if (array_key_exists('template_id', $args)) {
            $templateId = (int) $args['template_id'];

            // Ноль трактуем как «без шаблона»: отдельного аргумента для
            // отвязки агенту не объяснить, а null в JSON он шлёт неохотно.
            if ($templateId !== 0 && !TemplatePage::whereKey($templateId)->exists()) {
                return [
                    'error' => 'template_not_found',
                    'template_id' => $templateId,
                    'message' => 'Шаблона с таким id нет. Посмотри list_templates или создай его через create_template.',
                ];
            }

            $payload['template_page_id'] = $templateId ?: null;
        }

        if ($payload === []) {
            return ['error' => 'nothing_to_update', 'message' => 'Передай хотя бы одно из полей: title, alias, template_id, status.'];
        }

        $request = Request::create("/api/cms/pages/{$pageId}", 'PUT', $payload);
        $request->setUserResolver(fn () => auth()->user());

        $response = app(PageController::class)->update($request, $pageId);
        $data = $response->getData(true);

        if (($data['success'] ?? false) !== true) {
            return ['error' => 'save_failed', 'message' => $data['message'] ?? null];
        }

        $fresh = $page->fresh();

        return [
            'status' => 'saved',
            'page_id' => $pageId,
            // Адрес мог пересобраться из алиаса и родителя — показываем итог,
            // чтобы агент дал пользователю рабочую ссылку.
            'url' => $fresh?->url,
            'template_id' => $fresh?->template_page_id,
        ];
    }
}
