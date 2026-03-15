<?php

namespace Templite\Cms\Contracts;

use Illuminate\Http\Request;
use Templite\Cms\Models\Page;

/**
 * Value Object, передаваемый в каждый Action при выполнении.
 * Содержит весь необходимый контекст для работы action.
 */
class ActionContext
{
    public function __construct(
        /** Текущая страница, для которой рендерится блок */
        public Page $page,

        /** HTTP-запрос (для доступа к query-параметрам, например ?page=2 для пагинации) */
        public Request $request,

        /** Глобальные поля из app('global_fields') -- ассоциативный массив key => value */
        public array $global,

        /** Данные текущего блока (resolved) -- значения полей после BlockDataResolver */
        public array $blockData,
    ) {}
}
