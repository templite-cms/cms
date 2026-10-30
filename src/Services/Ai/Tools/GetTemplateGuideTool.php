<?php

namespace Templite\Cms\Services\Ai\Tools;

use Templite\Cms\Services\BladeSecurityValidator;

/**
 * Конвенции шаблона страницы — общей обёртки для блоков.
 */
class GetTemplateGuideTool extends GuideTool
{
    public function __construct(private readonly BladeSecurityValidator $blade)
    {
    }

    public function name(): string
    {
        return 'get_template_guide';
    }

    protected function guide(): array
    {
        return [
            'what_it_is' => 'Шаблон — общая обёртка страницы: <!DOCTYPE html>, head, шапка, подвал, подключение ассетов. Один шаблон обслуживает много страниц.',
            'blocks_placeholder' => "Блоки страницы подставляются директивой @yield('blocks'). Без неё страница отрендерится пустой — это самая частая ошибка в шаблоне.",
            'variables' => [
                'page' => 'модель текущей страницы',
                'global' => 'глобальные поля сайта — какие ключи заведены и что в них лежит, смотри в list_global_fields',
                'fields' => "поля самого шаблона: \$fields['ключ']. Значение задаёт каждая страница у себя, поэтому пиши {{ \$fields['ключ'] ?? 'запасной текст' }} — пока поле не заполнено, там пусто",
                'assets' => 'собранные css и js страницы плюс подключения библиотек — выводи их так же, как в существующих шаблонах',
                'breadcrumbs' => 'хлебные крошки',
                'lang' => 'код языка сайта (config app.locale) — для <html lang>',
            ],
            'allowed_directives' => $this->blade->getAllowedDirectives(),
            'whitelist_rule' => 'Это полный список разрешённых директив, он же действует в блоках. Всё остальное валидатор отклонит — включая @push, @stack, @forelse, @auth, @csrf, @class и @include. Для вычислений есть @php … @endphp. Скрипты и стили не «пушь» в стек, а выводи прямо в разметке шаблона через $assets.',
            'forbidden_syntax' => 'Тег <?php и <?= запрещены в любом виде.',
            'libraries' => 'Общие для всего сайта библиотеки подключай к шаблону через attach_library, а не к каждому блоку по отдельности.',
            'header_footer' => 'Шапку и подвал не верстай в шаблоне: это Blade-компоненты (get_component_guide), шаблон только вызывает их тегом <x-cms::site-header /> и <x-cms::site-footer />. Так их можно поправить в одном месте для всех шаблонов.',
            'site_data' => 'Логотип, телефоны, email, адрес, соцсети — глобальные поля ($global[\'ключ\'], см. list_global_fields и get_settings_guide), а не поля шаблона: иначе со вторым шаблоном данные разъедутся.',
            'fields_tool' => 'add_template_field — только для настройки, которая отличается между шаблонами и задаётся на странице (например, вариант оформления шапки). Общие данные сайта сюда не клади.',
            'workflow' => 'list_templates → get_template_code похожего шаблона (чтобы перенять вывод ассетов) → list_components (шапка и подвал могут уже быть) и list_global_fields → create_template → preview_template → update_page с template_id. Созданный шаблон сам по себе ни на одну страницу не встанет.',
        ];
    }
}
