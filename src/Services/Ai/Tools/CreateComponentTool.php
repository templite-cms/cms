<?php

namespace Templite\Cms\Services\Ai\Tools;

use Illuminate\Http\Request;
use Templite\Cms\Http\Controllers\Api\ComponentController;
use Templite\Cms\Services\Ai\EditorContext;

/**
 * Создание Blade-компонента: записи в базе и, если передан код, файлов.
 *
 * Компонент — кусок вёрстки, который зовётся из блоков и шаблонов тегом
 * <x-cms::slug />. Его стили и скрипты подключаются к странице автоматически,
 * когда он встречается в разметке.
 */
class CreateComponentTool implements Tool
{
    use ChecksCodePermission;

    /** Типы параметров — те же, что в редакторе (ComponentParamsManager.vue). */
    private const PARAM_TYPES = ['string', 'number', 'boolean', 'array', 'object'];

    public function name(): string
    {
        return 'create_component';
    }

    public function definition(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'name' => ['type' => 'string', 'description' => 'Название для админки'],
                'slug' => ['type' => 'string', 'description' => 'Ключ латиницей через дефис — им зовётся компонент: <x-cms::slug />'],
                'description' => ['type' => 'string', 'description' => 'Для чего компонент'],
                'params' => [
                    'type' => 'array',
                    'description' => 'Параметры компонента — что ему передавать. Их видно в админке на вкладках «Параметры» и «Переменные»',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'key' => ['type' => 'string', 'description' => 'Имя переменной внутри компонента, snake_case: title → $title'],
                            'name' => ['type' => 'string', 'description' => 'Название для админки'],
                            'type' => ['type' => 'string', 'enum' => self::PARAM_TYPES],
                            'default' => ['type' => 'string', 'description' => 'Значение по умолчанию для превью'],
                            'description' => ['type' => 'string'],
                        ],
                        'required' => ['key'],
                    ],
                ],
                'template' => ['type' => 'string', 'description' => 'Blade-разметка. Директива @props запрещена валидатором: параметры и так приходят переменными, а значения по умолчанию задавай через {{ $title ?? \'запасной текст\' }}'],
                'style' => ['type' => 'string', 'description' => 'SCSS компонента'],
                'script' => ['type' => 'string', 'description' => 'JS компонента'],
            ],
            'required' => ['name', 'slug'],
        ];
    }

    public function execute(array $args, ?EditorContext $ctx): array
    {
        if ($denied = $this->denyIfNoCodePermission('components.code')) {
            return $denied;
        }

        $params = $this->normalizeParams($args['params'] ?? null);

        if (isset($params['error'])) {
            return $params;
        }

        $payload = array_filter([
            'name' => (string) ($args['name'] ?? ''),
            'slug' => (string) ($args['slug'] ?? ''),
            'description' => $args['description'] ?? null,
            'params' => $params ?: null,
            // Компонент, созданный из чата, живёт в storage — как и созданный
            // через админку: его файлы можно править дальше.
            'source' => 'database',
        ], fn ($value) => $value !== null);

        $request = Request::create('/api/cms/components', 'POST', $payload);
        $request->setUserResolver(fn () => $this->currentManager());

        $response = app(ComponentController::class)->store($request);
        $created = $response->getData(true);

        if (($created['success'] ?? false) !== true) {
            return ['error' => 'create_failed', 'message' => $created['message'] ?? null];
        }

        $componentId = (int) ($created['data']['id'] ?? 0);
        $slug = $created['data']['slug'] ?? null;

        $code = array_filter([
            'template' => $args['template'] ?? null,
            'style' => $args['style'] ?? null,
            'script' => $args['script'] ?? null,
        ], fn ($value) => is_string($value) && $value !== '');

        if ($code === []) {
            return [
                'status' => 'created',
                'component_id' => $componentId,
                'slug' => $slug,
                'note' => 'Файлов у компонента пока нет — пока не сохранишь разметку через update_component_code, тег <x-cms::' . $slug . ' /> вызовет ошибку рендера.',
            ];
        }

        $saved = app(UpdateComponentCodeTool::class)->execute(
            ['component_id' => $componentId] + $code,
            $ctx,
        );

        if (isset($saved['error'])) {
            return $saved + [
                'status' => 'created_without_code',
                'component_id' => $componentId,
                'slug' => $slug,
                'next_step' => 'Запись уже создана. Исправь разметку и сохрани через update_component_code с этим component_id.',
            ];
        }

        return [
            'status' => 'created',
            'component_id' => $componentId,
            'slug' => $slug,
            'tag' => "<x-cms::{$slug} />",
        ];
    }

    /**
     * Параметры в том виде, в каком их держит редактор компонента:
     * список {key, name, type, default, description}.
     *
     * @return array<int,array<string,string>>|array{error:string,message:string}
     */
    private function normalizeParams(mixed $raw): array
    {
        if ($raw === null || $raw === []) {
            return [];
        }

        if (! is_array($raw) || ! array_is_list($raw)) {
            return ['error' => 'invalid_params', 'message' => 'params — список объектов {key, name, type, default, description}, а не словарь.'];
        }

        $params = [];

        foreach ($raw as $i => $item) {
            $key = is_array($item) ? (string) ($item['key'] ?? '') : '';
            $type = is_array($item) ? (string) ($item['type'] ?? 'string') : '';

            if (! preg_match('/^[a-z_][a-z0-9_]*$/', $key)) {
                return ['error' => 'invalid_params', 'message' => "params[{$i}].key — snake_case латиницей: им становится переменная \$key внутри компонента."];
            }

            if (! in_array($type, self::PARAM_TYPES, true)) {
                return ['error' => 'invalid_params', 'message' => "params[{$i}].type — одно из: " . implode(', ', self::PARAM_TYPES) . '.'];
            }

            $params[] = [
                'key' => $key,
                'name' => (string) ($item['name'] ?? $key),
                'type' => $type,
                'default' => (string) ($item['default'] ?? ''),
                'description' => (string) ($item['description'] ?? ''),
            ];
        }

        return $params;
    }
}
