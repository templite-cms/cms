# Templite: разбор прототипов на сущности CMS

Исходные данные — HTML-прототипы в `docs/prototypes/` с разметкой `<!-- COMPONENT: slug -->` и `<!-- BLOCK: slug -->`, плюс `docs/PROJECT.md` и `docs/DESIGN_BRIEF.md`.

## Строгий порядок создания (по зависимостям)
1. **Библиотеки** — шрифты и скрипты с CDN:
   - `list_libraries` — нужная может уже быть;
   - `search_cdn_libraries` → `add_cdn_library` (сначала посмотри файлы пакета, не угадывай имена);
   - подключение — через `attach_library` к шаблону (общие для сайта) или к блоку (нужные только ему). Подробно — `get_assets_guide`.
2. **Глобальные поля** — общие данные сайта: логотип, телефоны, email, адрес, меню, соцсети, реквизиты.
   - `get_settings_guide` — правила раскладки и ключей;
   - `list_global_fields` — что уже заведено;
   - `apply_settings_layout` — вкладка целиком одним вызовом: колонки, секции, поля; повторители (`array`) — с `children`;
   - `set_global_values` — заполнить значения.
3. **Компоненты** — шапка и подвал всегда компоненты `site-header` и `site-footer`:
   - `get_component_guide` → `list_components` (могут уже быть) → `create_component` → `preview_component`;
   - данные берут из `$global['ключ']`, не захардкоженным текстом.
4. **Шаблоны** — `get_template_guide` → `get_template_code` похожего (чтобы перенять вывод `$assets`) → `create_template` → `preview_template`:
   - полный HTML с DOCTYPE, `@yield('blocks')` для блоков;
   - шапка и подвал — тегами `<x-cms::site-header />` и `<x-cms::site-footer />`, не вёрсткой внутри шаблона;
   - поле шаблона (`add_template_field`) — только для того, что отличается между шаблонами, общие данные — в глобальных полях.
5. **Блоки** — по сценарию block-builder:
   `create_block_type` → `add_block_field` (дочерние поля повторителя — с `parent_id`) → `preview_block` → `update_block_code` → `create_block_preset` (`type: "local"`, `is_default: true`, реальный контент).
6. **Actions** — любая серверная логика (формы, внешние API, выборка данных):
   `get_action_guide` → `list_actions` → `create_action` → `test_action` → `attach_action`. Blade блока только выводит `$fields`, `$global` и `$actions`.
7. **Страницы** — `create_page` → `update_page { page_id, template_id }` → `add_block_to_page` с реальными данными.
   Экземплярам блоков с action — `set_block_cache { page_block_id, enabled: false }`.
8. **Картинки** — `upload_media_from_url` → id файла кладётся в поля `img`/`file` и в `set_global_values` (подробно — `get_media_guide`).

## Пример глобальных полей
```
apply_settings_layout {
  page: { name: "Общие", columns: 2, column_widths: [60, 40] },
  sections: [
    { name: "О сайте", column: 0, fields: [
      { key: "site_name", name: "Название сайта", type: "text" },
      { key: "logo", name: "Логотип", type: "img" },
      { key: "menu", name: "Меню", type: "array", children: [
        { key: "menu_title", name: "Пункт", type: "text" },
        { key: "menu_link", name: "Ссылка", type: "link" }
      ] }
    ] },
    { name: "Контакты", column: 1, fields: [
      { key: "phone", name: "Телефон", type: "text" },
      { key: "email", name: "Email", type: "text" }
    ] }
  ]
}

set_global_values { values: {
  "site_name": "Dental+",
  "phone": "+7 (495) 123-45-67",
  "email": "info@dental.ru",
  "menu": [ { "menu_title": "Услуги", "menu_link": { "url": "/uslugi", "target": "_self" } } ]
} }
```
Ключи глобальных полей уникальны по всему сайту, включая дочерние поля повторителей: `menu_link` и `socials_url`, а не `url` в обоих.

## Разметка прототипа
- `<!-- COMPONENT: slug -->` → `create_component` (`site-header`, `site-footer` и другие переиспользуемые куски).
- `<!-- BLOCK: slug -->` → `create_block_type` и далее по сценарию блока.

## HTML → Blade
| HTML | Blade |
|------|-------|
| Статичный текст | `{{ $fields['key'] }}` |
| Повторяющиеся карточки | поле `items` (тип `array`) + `@foreach` |
| Картинка | `<x-cms::image :file="$fields['photo']" />` |
| URL ссылки | `{{ $fields['button']['url'] }}` (поле `link`) |
| Условный элемент | `@if($fields['show_button'])` |
| Общие данные | `{{ $global['phone'] }}` |

Вывод каждого типа поля — `output_by_type` в `get_block_guide`.

## Какой тип поля брать
| Контент | Тип |
|---------|-----|
| Заголовок, подпись кнопки | text |
| Абзац, описание | textfield |
| Форматированный текст | editor / tiptap |
| Число, цена | number |
| Фото, иконка | img |
| Кнопка со ссылкой | link |
| Показать/скрыть элемент | checkbox |
| Цвет фона | color |
| Повторяющаяся группа | array + дочерние поля |
| Ссылка на страницу | page |

## Частые ошибки
- Компонент раньше глобальных полей — компонент выводит `$global`, ключей ещё нет.
- Шаблон раньше компонентов — шаблон вызывает `<x-cms::site-header />`.
- Шапка, подвал, контакты внутри шаблона или блока вместо компонентов и глобальных полей.
- Пустые данные в `add_block_to_page` без пресета по умолчанию — передавай реальный контент.
- Забыт `update_block_code` — блок рендерится пустым.
- Забыт пресет по умолчанию у нового блока.
- Стили блока в шаблоне — SCSS блока идёт в `style` блока.
- Библиотека создана, но не подключена через `attach_library`; action создан, но не подключён через `attach_action`.
- Блок с action оставлен в кэше.
