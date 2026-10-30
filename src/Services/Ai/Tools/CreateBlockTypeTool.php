<?php

namespace Templite\Cms\Services\Ai\Tools;

use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Templite\Cms\Http\Controllers\Api\BlockController;
use Templite\Cms\Services\Ai\EditorContext;

class CreateBlockTypeTool implements Tool
{
    public function name(): string
    {
        return 'create_block_type';
    }

    public function definition(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'name' => ['type' => 'string', 'description' => 'Название для админки, например «Блок отзывов»'],
                'slug' => ['type' => 'string', 'description' => 'Латиница через дефис, например reviews-grid'],
                'block_type_id' => ['type' => 'integer', 'description' => 'Категория из categories в ответе list_block_types; без неё берётся первая'],
                'description' => ['type' => 'string'],
                'tags' => ['type' => 'string', 'description' => 'Через запятую'],
            ],
            'required' => ['name', 'slug'],
        ];
    }

    public function execute(array $args, ?EditorContext $ctx): array
    {
        // Slug превращается в путь на диске — приводим к безопасному виду сразу.
        $slug = Str::slug((string) ($args['slug'] ?? ''));

        if ($slug === '') {
            return ['error' => 'invalid_slug', 'message' => 'Slug должен содержать латиницу.'];
        }

        // Категорию не указали — берём первую существующую, чтобы агент не тратил
        // шаги петли на угадывание идентификатора.
        $categoryId = (int) ($args['block_type_id'] ?? 0)
            ?: (int) \Templite\Cms\Models\BlockType::orderBy('order')->value('id');

        if (! $categoryId) {
            return ['error' => 'no_block_categories', 'message' => 'В CMS нет ни одной категории блоков.'];
        }

        $request = Request::create('/api/cms/blocks', 'POST', array_filter([
            'name' => $args['name'] ?? '',
            'slug' => $slug,
            'block_type_id' => $categoryId,
            'description' => $args['description'] ?? null,
            'tags' => $args['tags'] ?? null,
            'source' => 'database',
        ], fn ($v) => $v !== null));
        $request->setUserResolver(fn () => auth()->user());

        try {
            $response = app(BlockController::class)->store($request);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return ['error' => 'validation_failed', 'details' => $e->errors()];
        }

        $payload = $response->getData(true);

        return [
            'block_id' => $payload['data']['id'] ?? null,
            'slug' => $slug,
        ];
    }
}
