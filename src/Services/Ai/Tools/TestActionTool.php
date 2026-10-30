<?php

namespace Templite\Cms\Services\Ai\Tools;

use Templite\Cms\Models\Action;
use Templite\Cms\Models\Page;
use Templite\Cms\Services\ActionRegistry;
use Templite\Cms\Services\ActionRunner;
use Templite\Cms\Services\Ai\EditorContext;

/**
 * Прогон одного action — то же, что кнопка «Тест» в админке.
 *
 * Работает только вместе с защитой в ActionRegistry::loadFromFile(): без неё
 * тест сразу после сохранения кода убивал бы процесс повторным объявлением
 * класса, а вместе с ним — SSE-поток чата.
 */
class TestActionTool implements Tool
{
    use ChecksCodePermission;

    /** Потолок сериализованного результата: коллекция моделей вытеснила бы диалог из контекста. */
    private const MAX_RESULT_CHARS = 4000;

    public function name(): string
    {
        return 'test_action';
    }

    public function definition(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'action_id' => ['type' => 'integer', 'description' => 'ID action из list_actions'],
                'params' => ['type' => 'object', 'description' => 'Параметры прогона. Без них берутся значения default из params()'],
                'page_id' => ['type' => 'integer', 'description' => 'Страница, в контексте которой выполнять. По умолчанию первая'],
            ],
            'required' => ['action_id'],
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

        $pageId = (int) ($args['page_id'] ?? 0);
        $page = $pageId ? Page::find($pageId) : Page::query()->orderBy('id')->first();

        if (!$page) {
            return [
                'error' => 'page_not_found',
                'message' => 'Нет страницы, в контексте которой выполнить action. Создай страницу через create_page.',
            ];
        }

        // PHP не выгружает объявленные классы: если файл переписали после
        // того, как класс уже объявили, выполнится прежняя версия
        $stale = $this->staleCode($action);

        // Реестр сканирует storage один раз, при старте запроса, а файл мог
        // появиться позже: create_action пишет его в том же ходе агента.
        // Без пересканирования прогон отвечает «action не найден» ровно в
        // самом частом сценарии — «создал и сразу проверил», — а агент,
        // не поняв причины, заводит тот же action заново.
        $registry = app(ActionRegistry::class);
        $registry->scanStorageActions();

        // Реестр не знает action и после пересканирования: slug разошёлся с
        // именем файла либо файла нет вовсе. Своя ошибка вместо run_failed —
        // иначе агент принимает это за ошибку своего кода и правит рабочий.
        if (!$registry->exists($action->slug)) {
            return [
                'error' => 'action_not_registered',
                'slug' => $action->slug,
                'file_exists' => is_file(storage_path('cms/actions/' . basename($action->slug) . '.php')),
                'message' => "Реестр не знает action '{$action->slug}'. Код в файле не появился — сохрани его через update_action_code.",
            ];
        }

        try {
            $result = app(ActionRunner::class)->runSingle(
                $action->slug,
                is_array($args['params'] ?? null) ? $args['params'] : [],
                $page,
                request(),
            );
        } catch (\Throwable $e) {
            // Ошибка внутри handle() — рабочий исход: агент увидит сообщение
            // и поправит код, а не получит обрыв чата
            return ['error' => 'run_failed', 'message' => $e->getMessage()];
        }

        $encoded = json_encode($result, JSON_UNESCAPED_UNICODE);
        $truncated = mb_strlen($encoded) > self::MAX_RESULT_CHARS;

        return array_filter([
            'status' => 'ok',
            'page' => ['id' => $page->id, 'url' => $page->url],
            'keys' => array_keys($result),
            'result' => $truncated ? mb_substr($encoded, 0, self::MAX_RESULT_CHARS) . '…' : $result,
            'truncated' => $truncated ?: null,
            'warning' => $stale
                ? 'Код этого action уже загружался в текущем запросе — прогон выполнил прежнюю версию класса. Чтобы проверить свежий код, попроси пользователя повторить тест следующим сообщением.'
                : null,
        ], fn ($value) => $value !== null);
    }

    /**
     * Файл переписали после того, как класс уже объявили в этом процессе.
     *
     * Сам факт объявления класса ни о чём не говорит: при создании action код
     * загружается сразу же и как раз он и выполнится. Устарелость наступает
     * только когда содержимое файла разошлось с тем, из которого класс был
     * объявлен, — переобъявить его PHP не умеет.
     */
    private function staleCode(Action $action): bool
    {
        $path = storage_path('cms/actions/' . basename($action->slug) . '.php');

        if (!is_file($path)) {
            return false;
        }

        $loaded = ActionRegistry::loadedCodeHash($path);

        if ($loaded === null) {
            return false;
        }

        return !hash_equals($loaded, hash('sha256', (string) file_get_contents($path)));
    }
}
