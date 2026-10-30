# Конвенции Templite CMS

Подробные и всегда актуальные правила отдают справки — этот файл лишь краткая шпаргалка:
`get_block_guide` (блоки), `get_template_guide` (шаблоны), `get_component_guide` (компоненты), `get_action_guide` (actions), `get_settings_guide` (глобальные поля), `get_media_guide` (файлы), `get_assets_guide` (библиотеки и ассеты).

## Именование
- **Slug блока:** латиница через дефис — `hero-banner`, `services-grid`.
- **Ключи полей:** snake_case, с буквы — `button_text`, `bg_image`, `show_title`.
- **Slug компонента:** латиница через дефис — `site-header`, `site-footer`, `breadcrumbs`.

## Категории блоков
Категория (`block_type_id`) берётся из `categories` в ответе `list_block_types` — набор и id зависят от сайта, не угадывай их.

## Типы полей
`text` `textfield` `number` `img` `file` `editor` `tiptap` `html` `select` `checkbox` `radio` `link` `date` `datetime` `array` `color` `page`

## Как устроен сайт
- **Шапка и подвал** — Blade-компоненты `site-header` и `site-footer` (`create_component`); шаблон вызывает их тегами `<x-cms::site-header />` и `<x-cms::site-footer />`. Не верстай их в шаблоне или блоке.
- **Общие данные** — логотип, телефоны, email, адрес, соцсети, меню — только в глобальных полях (`apply_settings_layout` + `set_global_values`), в вёрстке `$global['ключ']`. Поля шаблона — лишь для того, что отличается между шаблонами.
- **Серверная логика** — выборки, внешние API, обработка форм — только в action. Blade блока лишь выводит `$fields`, `$global` и `$actions`.
- **Блок с action** не держи в кэше: после `attach_action` выключи кэш его экземпляров через `set_block_cache` (`enabled: false`).
- **Новый блок** — после полей и кода обязательно пресет по умолчанию: `create_block_preset` с `type: "local"`, `is_default: true` и реалистичным контентом.

## Blade

### Вывод полей по типам
Проверенная шпаргалка по каждому типу поля — `output_by_type` в `get_block_guide`. Там же полный список разрешённых директив: всё, чего нет в списке, валидатор отклонит.

```blade
{{-- Простое поле --}}
{{ $fields['title'] }}

{{-- Картинка (поле img блока — модель File) --}}
@if($fields['photo'])
  <x-cms::image :file="$fields['photo']" />
@endif

{{-- Повторитель --}}
@foreach($fields['items'] as $item)
  <div>{{ $item['title'] }}</div>
  <x-cms::image :file="$item['icon']" />
@endforeach

{{-- Условие и ссылка (поле link — массив url/text/target) --}}
@if($fields['show_button'])
  <a href="{{ $fields['button']['url'] }}" target="{{ $fields['button']['target'] }}">{{ $fields['button']['text'] }}</a>
@endif
```

### Файлы в глобальных полях и полях шаблона
В `$global[...]` и в сырых `template_data[...]` страницы img/file хранятся как **id (int)**, а не модель File:
```blade
<x-cms::image :id="$global['logo']" />
<img src="{{ cms_file_url($global['logo']) }}" alt="">
<img src="{{ cms_file_url($global['logo'], 'thumb') }}" alt="">   {{-- ключ размера --}}
{{ cms_file($global['logo'])?->url }}
```

### Правила и подводные камни
- Производные значения считай в `@php ... @endphp` или прямо в `{{ }}` (`number_format`, `nl2br`, `\Carbon\Carbon::parse`, `cms_file_url`). Теги `<?php` запрещены.
- Запрещены «жёсткие» возможности: запуск процессов, `eval`/`include`, вызов функции по имени из строки или переменной, прямая работа с файлами и сетью, `ini_set`/`putenv` — список в `get_block_guide` (`hard_limits`).
- Отсутствующие ключи в элементах повторителя безопасны: `$item['missing'] ?? 'запасной'` работает.
- Значения по умолчанию у полей нет: незаполненное поле приходит пустым значением своего типа (`''`, `0`, `false`, `[]`, `null` для img/file/page, link — `['url' => '', 'text' => '', 'target' => '_self']`). Картинку и страницу выводи под `@if`.
- Дочерние ключи повторителя есть только внутри элементов (`$item['title']`), на верхнем уровне `$fields` их нет.
- Поля блока и шаблона — плоский упорядоченный список, вложенность только в повторителе через `parent_id`. Вкладки и секции бывают только у глобальных полей.
- Стартовый контент — из пресета по умолчанию: `add_block_to_page` без `data` заполняет блок из него, без пресета блок придёт пустым.
- Блок, связанный с **глобальным пресетом** (`linked_preset` в `get_page`), выводит данные пресета целиком; `update_block_data` на нём ничего не меняет — правь пресет (`update_block_preset`) или отвяжи блок (`apply_preset_to_block` с `unlink: true`).

## Глобальные поля (настройки)

Иерархия: **вкладка → секция (в колонке) → поле**. Правила — `get_settings_guide`.

- `list_global_fields` — структура и текущие значения.
- `apply_settings_layout` — собрать вкладку целиком: колонки, секции, поля; повторители — `type: "array"` с `children`. Ничего не удаляет.
- `update_settings_field` — поправить одно поле (ключ менять нельзя).
- `set_global_values { values: { "key": "value" } }` — заполнить значения; повторитель — списком объектов, img/file — id из `list_media`.

Ключи глобальных полей уникальны по всему сайту, включая дочерние поля повторителей.

```blade
{{ $global['site_name'] }}
{{ $global['phone'] }}
@foreach($global['menu'] as $item)
  <a href="{{ $item['menu_link']['url'] }}">{{ $item['menu_title'] }}</a>
@endforeach
```

**Типичный набор для сайта:**
| Ключ | Тип | Пример |
|------|-----|--------|
| site_name | text | «Dental+» |
| phone | text | «+7 (495) 123-45-67» |
| email | text | «info@dental.ru» |
| address | text | «Москва, ...» |
| working_hours | text | «Пн–Пт 9:00–18:00» |
| logo | img | id файла; вывод `<x-cms::image :id="$global['logo']" />` |
| menu | array | дочерние: menu_title (text), menu_link (link) |
| socials | array | дочерние: socials_type (select), socials_url (link) |

## SCSS
```scss
// БЭМ: .block-slug { &__element { &--modifier } }
.hero-banner {
  padding: 80px 0;
  &__title { font-size: 2.5rem; font-weight: 700; }
  &__subtitle { font-size: 1.125rem; color: rgb(var(--color-text-muted)); }
  &__cta { margin-top: 30px; }
}
```
Цвета — токены `rgb(var(--токен))` из `get_block_guide`; без Tailwind и утилитарных классов.

## Шаблон страницы

Шаблон — **полный HTML**: DOCTYPE, head, body. Блоки страницы подставляются `@yield('blocks')` — без неё страница пустая. Подробности и список переменных — `get_template_guide`.

```blade
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <x-cms::meta-tags :page="$page" />
    {!! $assets['libraries_css_html'] ?? '' !!}
    @if(!empty($assets['css']))
        <link rel="stylesheet" href="{{ $assets['css'] }}">
    @endif
</head>
<body>
    <x-cms::site-header />

    @yield('blocks')

    <x-cms::site-footer />

    {!! $assets['libraries_js_html'] ?? '' !!}
    @if(!empty($assets['js']))
        <script src="{{ $assets['js'] }}"></script>
    @endif
</body>
</html>
```

### Переменные шаблона
- `$page` — модель страницы (title, url, seo_data).
- `$global` — глобальные поля (`$global['phone']`).
- `$fields` — поля самого шаблона: `{{ $fields['ключ'] ?? 'запасной текст' }}`.
- `$breadcrumbs` — хлебные крошки.
- `$assets` — ассеты страницы:
  - `css`, `js` — адреса собранных бандлов страницы;
  - `libraries_css_html`, `libraries_js_html` — **используй их**: готовая разметка библиотек с флагами файлов (`media`, `defer`, `async`, `type="module"`) и `?v=`. Выводи через `{!! !!}`;
  - `cdn_css`, `cdn_js` — устаревшие списки адресов без флагов, только для старых шаблонов.
- `$lang` — код языка сайта (`config('app.locale')`), для `<html lang="…">`.

## Принцип трёх источников (приоритет)
1. `app/` — код разработчика (ВЫСШИЙ).
2. `storage/cms/` — из админки (СРЕДНИЙ); только эти файлы правятся через CMS.
3. `vendor/templite/` — из пакета (НИЗШИЙ).
