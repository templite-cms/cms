<?php

namespace Templite\Cms\Services\Ai\Tools;

use Illuminate\Http\Request;
use Templite\Cms\Http\Controllers\Api\ActionController;
use Templite\Cms\Services\Ai\EditorContext;

/**
 * Создание action: запись в базе и, если передан код, сразу файл на диске.
 *
 * Два вызова контроллера вместо одного — не прихоть: POST /actions код не
 * принимает вовсе, его валидация знает только метаданные.
 */
class CreateActionTool implements Tool
{
    use ChecksCodePermission;

    public function name(): string
    {
        return 'create_action';
    }

    public function definition(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'name' => ['type' => 'string', 'description' => 'Название для админки'],
                'slug' => ['type' => 'string', 'description' => 'Ключ латиницей через дефис. По нему action зовётся в шаблоне: $actions[\'slug\']'],
                'description' => ['type' => 'string', 'description' => 'Что делает action'],
                'allow_http' => ['type' => 'boolean', 'description' => 'Разрешить вызов по адресу /action/{slug} — нужно для форм и ajax'],
                'code' => ['type' => 'string', 'description' => 'PHP-код класса целиком. Структуру и ограничения смотри в get_action_guide'],
            ],
            'required' => ['name', 'slug'],
        ];
    }

    public function execute(array $args, ?EditorContext $ctx): array
    {
        if ($denied = $this->denyIfNoCodePermission('actions.code')) {
            return $denied;
        }

        $payload = array_filter([
            'name' => (string) ($args['name'] ?? ''),
            'slug' => (string) ($args['slug'] ?? ''),
            'description' => $args['description'] ?? null,
            'allow_http' => isset($args['allow_http']) ? (bool) $args['allow_http'] : null,
        ], fn ($value) => $value !== null);

        $request = Request::create('/api/cms/actions', 'POST', $payload);
        $request->setUserResolver(fn () => $this->currentManager());

        // Занятый slug и пустое имя прилетят сюда ValidationException —
        // ToolRegistry превратит его в validation_failed с полями
        $response = app(ActionController::class)->store($request);
        $created = $response->getData(true);

        if (($created['success'] ?? false) !== true) {
            return ['error' => 'create_failed', 'message' => $created['message'] ?? null];
        }

        $actionId = (int) ($created['data']['id'] ?? 0);
        $slug = $created['data']['slug'] ?? null;
        $code = $args['code'] ?? null;

        if (!is_string($code) || trim($code) === '') {
            return [
                'status' => 'created',
                'action_id' => $actionId,
                'slug' => $slug,
                'note' => 'Кода у action пока нет — до update_action_code он ничего не вернёт блоку.',
            ];
        }

        $saved = app(UpdateActionCodeTool::class)->execute(
            ['action_id' => $actionId, 'code' => $code],
            $ctx,
        );

        // Запись остаётся и при отказе валидатора: откат вернул бы агента к
        // пустому месту, он завёл бы action заново и упёрся в занятый slug.
        //
        // Подсказка идёт отдельным ключом, а не hint: при отказе валидатора hint
        // уже занят ссылкой на get_action_guide, а оператор + при совпадении
        // ключей оставляет значение левого массива — своё сообщение потерялось бы
        // молча, и агент как раз пошёл бы создавать action заново.
        if (isset($saved['error'])) {
            return $saved + [
                'status' => 'created_without_code',
                'action_id' => $actionId,
                'slug' => $slug,
                'next_step' => 'Запись уже создана. Исправь код и сохрани его через update_action_code с этим action_id — повторный create_action упрётся в занятый slug.',
            ];
        }

        return ['status' => 'created', 'action_id' => $actionId, 'slug' => $slug] + $saved;
    }
}
