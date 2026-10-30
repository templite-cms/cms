<?php

namespace Templite\Cms\Services\Ai\Tools;

use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Templite\Cms\Http\Controllers\Api\ActionController;
use Templite\Cms\Models\Action;
use Templite\Cms\Services\ActionRegistry;
use Templite\Cms\Services\Ai\EditorContext;

/**
 * Запись PHP-кода action.
 *
 * Сам код не трогает: вся работа — у Api\ActionController::update(), который
 * прогоняет его через ActionCodeValidator, кладёт файл в storage/cms/actions,
 * считает хэш целостности, вытягивает params()/returns() и пишет аудит-лог.
 */
class UpdateActionCodeTool implements Tool
{
    use ChecksCodePermission;

    public function name(): string
    {
        return 'update_action_code';
    }

    public function definition(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'action_id' => ['type' => 'integer', 'description' => 'ID action из list_actions или create_action'],
                'code' => ['type' => 'string', 'description' => 'PHP-код класса целиком, от <?php до последней скобки'],
            ],
            'required' => ['action_id', 'code'],
        ];
    }

    public function execute(array $args, ?EditorContext $ctx): array
    {
        $actionId = (int) ($args['action_id'] ?? 0);
        $action = Action::find($actionId);

        if (!$action) {
            return ['error' => 'action_not_found', 'action_id' => $actionId];
        }

        if ($denied = $this->denyIfNoCodePermission('actions.code')) {
            return $denied;
        }

        // Реестр ищет в порядке app > storage > vendor, а контроллер пишет
        // всегда в storage: для action из app или пакета файл лёг бы на диск
        // и никогда не выполнился — агент отчитался бы об успехе впустую
        if ($action->source !== 'storage') {
            return [
                'error' => 'not_editable',
                'source' => $action->source,
                'message' => "Код этого action лежит в {$action->source} и перекрывает storage по приоритету реестра. Менять его можно только в файлах проекта.",
            ];
        }

        $code = (string) ($args['code'] ?? '');

        // Открыт редактор этого action — правку отдаём в форму на подтверждение:
        // запись на диск прожила бы ровно до нажатия «Сохранить»
        if ($ctx && $ctx->isActionEditorOpen($actionId)) {
            return [
                'status' => 'proposed_to_editor',
                'editor_op' => [
                    'op' => 'update_action_code',
                    'payload' => ['action_id' => $actionId, 'code' => $code],
                ],
            ];
        }

        $request = Request::create("/api/cms/actions/{$actionId}", 'PUT', ['code' => $code]);
        $request->setUserResolver(fn () => $this->currentManager());

        try {
            $response = app(ActionController::class)->update($request, $actionId);
        } catch (ValidationException $e) {
            // Отказ валидатора — рабочий исход, а не сбой: агент видит, какие
            // именно конструкции запрещены, и чинит код следующим вызовом
            return [
                'error' => 'validation_failed',
                'details' => $e->errors(),
                'hint' => 'Список разрешённых функций и классов — в get_action_guide.',
            ];
        }

        $payload = $response->getData(true);

        if (($payload['success'] ?? false) !== true) {
            return ['error' => 'save_failed', 'message' => $payload['message'] ?? null];
        }

        // Контроллер подключил файл сам — через require_once в разборе
        // сигнатуры, мимо реестра. Отмечаем, какой код при этом объявил
        // класс: иначе test_action не отличит свежую загрузку от повторного
        // сохранения поверх уже объявленного класса и предупреждал бы всегда.
        ActionRegistry::rememberLoadedCode(
            storage_path('cms/actions/' . basename($action->slug) . '.php'),
            $code,
        );

        return [
            'status' => 'saved',
            'action_id' => $actionId,
            // Сигнатуру контроллер вытащил из самого класса — агенту полезно
            // увидеть, что распозналось, прежде чем подключать action к блоку
            'params' => $payload['data']['params'] ?? null,
            'returns' => $payload['data']['returns'] ?? null,
        ];
    }
}
