<?php

namespace Templite\Cms\Services\Ai\Tools;

use Templite\Cms\Services\ActionCodeValidator;

/**
 * Справка по написанию кода action.
 *
 * Whitelist берётся из самого валидатора, а не копируется в текст: иначе
 * справка разошлась бы с проверкой при первом пополнении списка, и агент
 * получал бы отказ на функцию, которую она сама же и разрешила.
 */
class GetActionGuideTool extends GuideTool
{
    public function __construct(private readonly ActionCodeValidator $validator)
    {
    }

    public function name(): string
    {
        return 'get_action_guide';
    }

    protected function guide(): array
    {
        return [
            'interface' => [
                'params' => 'array<string,array> — входные параметры: type, label, default, required, options. По ним админка строит форму настройки.',
                'returns' => 'array<string,array> — описание возвращаемых ключей: type, description. Идёт в подсказки редактора кода блока.',
                'handle' => 'handle(array $params, ActionContext $context): array — возвращённый массив попадает в шаблон блока.',
                'csrfEnabled' => 'bool — требовать ли CSRF-токен при HTTP-вызове /action/{slug}.',
            ],
            'context_fields' => [
                'page' => 'Templite\Cms\Models\Page — страница, для которой рендерится блок',
                'request' => 'Illuminate\Http\Request — запрос: query-параметры, пагинация',
                'global' => 'array — глобальные поля сайта, ключ => значение; список ключей смотри в list_global_fields',
                'blockData' => 'array — значения полей блока после резолвинга',
            ],
            'usage_in_blade' => "Результат доступен в шаблоне блока как \$actions['slug']['ключ'] — но только у того типа блока, к которому action подключён через attach_action.",
            'params_merge_order' => 'default из params() → параметры привязки к типу блока (attach_action) → параметры экземпляра блока на странице (set_action_params)',
            'workflow' => 'list_actions (нужный action может уже существовать) → create_action с кодом → test_action (проверить прогон) → attach_action к типу блока. Без attach_action код не выполнится ни на одной странице: блок отрендерится пустым, и пользователь не поймёт, почему. Если вёрстка блока опирается на данные action, подключай его в том же ходе, что и правишь код блока.',
            'on_validation_error' => 'Отказ валидатора приходит списком строк с номерами — исправляй по нему точечно, а не переписывай код наугад.',
            'when_to_use' => 'Любая серверная логика — выборка из базы, внешние API, обработка форм — живёт только в action. В Blade блока логики нет: он выводит $fields, $global и $actions.',
            'cache' => 'Кэш HTML — флаг у каждого блока на странице (cache_enabled в get_page), по умолчанию включён. Закэшированный блок с action покажет всем один и тот же результат, поэтому после attach_action выключи кэш его экземпляров через set_block_cache — attach_action вернёт их список. Action, который вызывается только из JS через /action/{slug}, к блоку не подключается и кэшу не мешает.',
            'allow_http' => 'allow_http=true открывает вызов action по адресу /action/{slug} — нужно для форм и ajax. Требовать ли CSRF-токен, решает csrfEnabled() в коде.',
            'restrictions' => [
                'Ровно один именованный класс в файле, обязательно implements BlockActionInterface.',
                'Анонимные классы (new class), eval, exit/die, include/require и backtick-оператор запрещены.',
                'Создание объекта через переменную (new $var) и вызов функции через переменную ($fn()) запрещены.',
                'new разрешён только для классов из allowed_classes, а также любых моделей Templite\Cms\Models\* и App\Models\*.',
                'Вызовы методов объектов и статические вызовы (Page::query()) не ограничиваются — ограничен список свободных функций.',
            ],
            'allowed_functions' => $this->validator->getAllowedFunctions(),
            'allowed_classes' => $this->validator->getAllowedClasses(),
            'stub' => $this->stub(),
        ];
    }

    /** Эталонный шаблон класса — тот же, что подставляется в редакторе нового action. */
    private function stub(): string
    {
        $path = dirname(__DIR__, 4) . '/stubs/action.stub';

        return is_file($path) ? (string) file_get_contents($path) : '';
    }
}
