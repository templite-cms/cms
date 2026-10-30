# Templite: полный цикл создания сайта

## Бриф
{{brief}}

## Конвейер — по порядку, с паузой на согласование между этапами

### 0. Разведка
`list_pages`, `list_block_types`, `list_templates`, `list_components`, `list_global_fields` — CMS пустая?
- Пустая → начинай с этапа 1.
- Не пустая → сначала выполни сценарий project-sync, затем этап 1.

### 1. Аналитик — требования
- Спроси пользователя: название компании, город, услуги, аудитория.
- Найди в вебе примеры и конкурентов в нише.
- Создай `docs/PROJECT.md`: структура сайта, страницы → блоки, общие данные сайта, контент.
- **ПАУЗА:** покажи PROJECT.md пользователю, получи согласие.

### 2. Дизайнер — визуальный бриф
- Прочитай PROJECT.md и референсы.
- Определи палитру (CSS-переменные), типографику, UI-компоненты, вид каждого блока.
- Создай `docs/DESIGN_BRIEF.md` (сценарий designer).
- **ПАУЗА:** покажи пользователю, получи согласие.

### 3. Фронтенд — HTML-прототипы
- Прочитай PROJECT.md и DESIGN_BRIEF.md.
- Сделай самодостаточные HTML-страницы в `docs/prototypes/` (сценарий frontend-proto).
- Разметь `<!-- COMPONENT: slug -->` и `<!-- BLOCK: slug -->`, БЭМ-классы, встроенный CSS, реальный контент.
- **ПАУЗА:** покажи скриншоты, получи согласие.

### 4. Бэкенд — сборка в CMS
Строгий порядок: библиотеки → глобальные поля → компоненты → шаблоны → блоки → страницы. Подробно — сценарий backend-decompose.

1. **Библиотеки** (шрифты, скрипты с CDN): `list_libraries` → `search_cdn_libraries` → `add_cdn_library`. Общие для сайта подключи к шаблону на шаге 4 через `attach_library`.
2. **Глобальные поля** — общие данные сайта (логотип, телефоны, email, адрес, меню, соцсети):
   - `get_settings_guide` → `list_global_fields` — что уже заведено;
   - `apply_settings_layout` — вкладка целиком: колонки, секции, поля (меню и соцсети — повторители `array` с `children`);
   - `set_global_values { values: { "site_name": "...", "phone": "..." } }` — заполнить значения.
3. **Компоненты** из `<!-- COMPONENT -->`: `get_component_guide` → `create_component` для `site-header` и `site-footer` (данные — из `$global['ключ']`) → `preview_component`.
4. **Шаблон**: `get_template_guide` → `create_template` (полный HTML, `@yield('blocks')`, вызов `<x-cms::site-header />` и `<x-cms::site-footer />`) → `preview_template` → `attach_library` для общих библиотек.
5. **Блоки** из `<!-- BLOCK -->` — по сценарию block-builder: `create_block_type` → `add_block_field` (дочерние — с `parent_id`) → `preview_block` → `update_block_code` → `create_block_preset` (`type: "local"`, `is_default: true`).
6. **Серверная логика** (формы, внешние API, выборки) — только в action: `get_action_guide` → `create_action` → `test_action` → `attach_action`.
7. **Страницы**: `create_page` → `update_page { template_id }` → `add_block_to_page` с реальным контентом. Блокам с action — `set_block_cache { enabled: false }`.
8. **Картинки**: `upload_media_from_url` → id файла в поля `img`/`file` (подробно — `get_media_guide`).

### 5. Проверка
- Открой каждую страницу, проверь, что блоки рендерятся.
- Проверь консоль на ошибки.
- Проверь адаптивность на мобильном.
- Проверь ссылки навигации.
- Сообщи о проблемах, исправь через MCP, проверь снова.

## Ключевые конвенции
- **Slug:** латиница через дефис (`hero-banner`).
- **Ключи полей:** `snake_case` (`button_text`).
- **Blade:** `{{ $fields['key'] }}`, `$global['key']` для общих данных.
- **Шапка и подвал:** компоненты `site-header` / `site-footer`, шаблон вызывает их тегом.
- **Шаблон:** полный HTML (DOCTYPE, head, body), блоки — через `@yield('blocks')`.
- **SCSS:** БЭМ, CSS-переменные из дизайн-брифа.
- **Контент:** всегда реальный текст, никакого Lorem ipsum.
