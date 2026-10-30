<?php

namespace Templite\Cms\Services\Ai\Tools;

use Templite\Cms\Services\BladeSecurityValidator;

/**
 * Конвенции вёрстки блока: Blade, поля, SCSS и JS.
 *
 * Почти всё здесь — правила, за нарушение которых валидатор отклоняет код
 * или блок ломается уже на сайте, а не в превью.
 */
class GetBlockGuideTool extends GuideTool
{
    public function __construct(private readonly BladeSecurityValidator $blade)
    {
    }

    public function name(): string
    {
        return 'get_block_guide';
    }

    protected function guide(): array
    {
        return [
            'blade' => [
                'variables' => "Данные полей доступны как \$fields['ключ']. Также доступны \$global (глобальные поля сайта — какие ключи заведены и что в них лежит, смотри в list_global_fields), \$actions (данные подключённых actions).",
                // Список отдаёт сам валидатор: перечислять его текстом значит
                // однажды разойтись с проверкой. Ровно на этом агент уже
                // обжёгся — в подсказке были @php и @include, а спотыкался он
                // о @push и @forelse и решил, что сломана платформа.
                'allowed_directives' => $this->blade->getAllowedDirectives(),
                'whitelist_rule' => 'Это полный список. Любая директива вне его запрещена — в том числе привычные @push, @stack, @forelse, @auth, @csrf, @class, @json, @include. Заменяй их разрешёнными конструкциями.',
                'forbidden_syntax' => 'Теги <?php и <?= запрещены — для вычислений в шаблоне есть @php … @endphp.',
                'php_blocks' => "Производные значения считай в @php … @endphp: @php \$tel = preg_replace('/\\D/', '', \$global['phone']); @endphp. Логику с данными (выборки, внешние API) по-прежнему держи в action.",
                'hard_limits' => 'В {{ }}, аргументах директив и @php запрещены запуск процессов (exec, system, обратные кавычки), eval и include, вызов функции по имени из строки или переменной (call_user_func, $f()), прямая работа с файлами и сетью (file_get_contents, fopen, curl_exec), подмена окружения (ini_set, putenv).',
                'images' => 'Изображения выводи компонентом <x-cms::image :file="$fields[\'image\']" /> — он сам делает webp-фолбэк и размеры.',
            ],
            'fields' => [
                'keys' => 'snake_case, начинаются с буквы: button_text, bg_image.',
                'repeatable' => 'Для повторяющихся элементов заводи поле типа array и вкладывай в него дочерние поля через parent_id.',
                'flat_list' => 'Поля — плоский список в порядке order; вкладок и секций у полей блока нет.',
                'repeater_scope' => "Ключи дочерних полей есть только внутри элементов повторителя: @foreach(\$fields['items'] as \$item) {{ \$item['title'] }} @endforeach. На верхнем уровне \$fields их нет.",
                'empty_values' => "Значения по умолчанию у полей нет. Незаполненное поле приходит пустым значением своего типа: '' для текстовых, 0 для number, false для checkbox, [] для array, null для img/file/page, для link — ['url' => '', 'text' => '', 'target' => '_self']. Картинку и страницу выводи только под @if.",
                'starting_content' => 'Стартовый контент блока задаёт его пресет по умолчанию: add_block_to_page без data и preset_id заполняет блок из него. Создавая новый блок, после полей и кода обязательно заведи create_block_preset с type: local, is_default: true и реалистичным контентом по всем полям — иначе блок, добавленный из каталога вручную, придёт пустым.',
                'media_values' => "В полях типа img и file значение — это ID файла (число) из list_media, а не имя и не путь.",
                // Каждый пример проверен валидатором и рендером на реальных File и Page.
                'output_by_type' => [
                    'text, textfield, select, radio, color, date, datetime' => "Строка ('' если пусто): {{ \$fields['key'] }}. Многострочный textfield с переносами: {!! nl2br(e(\$fields['key'])) !!}. Дата в своём формате: {{ \$fields['key'] ? \\Carbon\\Carbon::parse(\$fields['key'])->format('d.m.Y') : '' }}.",
                    'editor, html' => "Готовый HTML: {!! \$fields['key'] !!}.",
                    'tiptap' => "HTML редактора с обработкой картинок: <x-cms::tiptap :content=\"\$fields['key']\" />.",
                    'number' => "Число (0 если пусто): {{ number_format(\$fields['key'], 0, '.', ' ') }}.",
                    'checkbox' => "bool: @if(\$fields['key']) … @endif.",
                    'link' => "Массив url/text/target (всегда все три ключа): <a href=\"{{ \$fields['key']['url'] }}\" target=\"{{ \$fields['key']['target'] }}\">{{ \$fields['key']['text'] }}</a>.",
                    'img, file' => "Модель File или null: <x-cms::image :file=\"\$fields['key']\" /> или {{ \$fields['key']?->url }}.",
                    'page' => "Модель Page или null: {{ \$fields['key']?->title }}.",
                    'array' => "Список элементов с ключами дочерних полей: @foreach(\$fields['key'] as \$item) {{ \$item['title'] }} @endforeach. Вложенные img — тоже File: <x-cms::image :file=\"\$item['icon']\" />.",
                    'global img/file' => "В \$global картинка хранится id файла, не моделью: <x-cms::image :id=\"\$global['logo']\" /> или {{ cms_file_url(\$global['logo']) }}.",
                ],
            ],
            'scss' => [
                'naming' => 'БЭМ от slug блока: .block-slug { &__element {} &--modifier {} }. Без Tailwind и утилитарных классов.',
                'scope' => 'Обёртки у блока нет, стили выводятся как написаны: изоляция держится на префиксе slug в каждом селекторе. Правило без него (.title, h2, .container) применится ко всей странице. :scope — корень блока ([data-block="slug"]). Не пиши :root, html, body и @font-face — это глобальное, ему место в шаблоне; @keyframes называй с префиксом slug. Нарушения не мешают сохранению, но update_block_code вернёт их в warnings.',
                'colors' => 'Только rgb(var(--токен)) из набора ниже — других переменных в проекте нет.',
                'tokens' => [
                    'surface' => '--color-surface-page, --color-surface-card, --color-surface-muted, --color-surface-hover, --color-surface-active, --color-surface-input, --color-surface-selected, --color-surface-header',
                    'text' => '--color-text-primary, --color-text-secondary, --color-text-muted',
                    'border' => '--color-border-default, --color-border-light',
                ],
            ],
            'javascript' => [
                'root' => 'Корень блока — первый элемент шаблона (section, header, nav — какой напишешь): CMS ничего вокруг не добавляет, только ставит на него data-block="slug". Держи в шаблоне один корневой элемент. Иначе — метку вручную: <section {{ $block->marker }}>.',
                'el' => 'Код блока — тело функции: рантайм вызывает его для каждого экземпляра блока на странице и передаёт корень как el. Ищи элементы внутри него — el.querySelector(...), а не document.querySelector(...): при двух одинаковых блоках поиск по document оба раза найдёт первый.',
                'timing' => 'Код запускается, когда DOM готов, — на сайте и в превью одинаково. Ждать DOMContentLoaded не нужно (подписка сработает, но смысла в ней нет), return на верхнем уровне допустим.',
                'no_marker' => 'Если у блока выключена метка data-block и её нет в шаблоне, код выполнится один раз на странице с el = null.',
                'hidden_start' => 'Если вёрстка стартует из скрытого состояния (opacity: 0, transform) и показывается классом из JS, сломанный скрипт оставит блок невидимым целиком, а не просто без анимации. Задавай стартовое состояние так, чтобы контент оставался читаемым и без JS.',
            ],
            'presets' => [
                'local' => 'Локальный пресет — шаблон: при добавлении блока его данные копируются в блок, связи не остаётся. Годится для вариантов оформления и стартового контента.',
                'global' => 'Глобальный пресет — связь: связанный блок выводит данные пресета целиком, своих данных не показывает. Годится для одинакового блока на многих страницах (контакты, призыв к действию).',
                'confirm' => 'Правка data глобального пресета со связанными блоками меняет их на всех страницах. update_block_preset без confirm вернёт used_on — покажи пользователю список страниц, спроси, и только после согласия повтори с confirm: true. Сам confirm: true без вопроса не ставь.',
                'from_page' => 'Чтобы сохранить наполненный блок со страницы как пресет, передай from_page_block_id — данные возьмутся из блока (с несохранёнными правками открытого редактора), переписывать их в data не нужно.',
                'apply' => 'apply_preset_to_block: preset_id локального перезаписывает данные блока, preset_id глобального связывает, unlink: true отвязывает и оставляет блоку данные пресета.',
            ],
            'workflow' => 'Посмотри код похожего блока через get_block_code → создай блок и поля → проверь черновик через preview_block → и только после успешного рендера сохраняй через update_block_code → создай пресет по умолчанию (create_block_preset).',
        ];
    }
}
