<?php

namespace Templite\Cms\Services\Ai\Tools;

use Templite\Cms\Services\BladeSecurityValidator;

/**
 * Конвенции Blade-компонентов — переиспользуемой вёрстки.
 */
class GetComponentGuideTool extends GuideTool
{
    public function __construct(private readonly BladeSecurityValidator $blade)
    {
    }

    public function name(): string
    {
        return 'get_component_guide';
    }

    protected function guide(): array
    {
        return [
            'what_it_is' => 'Компонент — кусок вёрстки, который зовётся и из блока, и из шаблона: шапка, подвал, карточка, слайдер.',
            'usage' => 'Тег <x-cms::slug />. Параметры передаются атрибутами: <x-cms::card :title="$fields[\'title\']" text="Строкой" />.',
            'params_inside' => 'Внутри компонента параметры доступны обычными переменными ($title, $items).',
            'no_props' => "Директива @props запрещена валидатором — значения по умолчанию задавай через {{ \$title ?? 'запасной текст' }}. У компонентов из пакета @props встречается: они не проходят через валидатор, повторять за ними нельзя.",
            'allowed_directives' => $this->blade->getAllowedDirectives(),
            'whitelist_rule' => 'Это полный список разрешённых директив, общий для блоков, шаблонов и компонентов. Всё вне его валидатор отклонит — включая @props, @push, @forelse, @auth, @class и @include.',
            'assets' => 'Стили и скрипты компонента подключаются к странице сами, когда его тег встречается в разметке. Писать для них теги не нужно.',
            'styles_are_global' => 'В отличие от блока, компонент не оборачивается в служебный div — его тег может стоять хоть внутри <nav>, хоть в <head>. Поэтому стили компонента глобальные: именуй классы от его slug (.site-header__logo), не пиши голые селекторы вроде p или .title — они разъедутся по всей странице.',
            'header_footer' => 'Шапка и подвал сайта — всегда компоненты (обычно site-header и site-footer), подключённые к шаблону тегом. Данные в них — логотип, контакты, соцсети — бери из глобальных полей ($global[\'ключ\']), а не параметрами из каждого шаблона.',
            'reuse_first' => 'Перед тем как верстать шапку, подвал, карточку или слайдер заново, посмотри list_components — возможно, нужный компонент уже есть. Код читается через get_component_code.',
            'editable' => 'Править можно только компоненты с editable=true (файлы в storage). Компоненты из пакета и app перекрывают storage по приоритету реестра — запись в них не подействует.',
            'blast_radius' => 'Правка компонента отражается везде, где он используется. Перед сохранением проверь рендер через preview_component.',
            'workflow' => 'list_components → get_component_code → create_component или update_component_code → preview_component.',
        ];
    }
}
