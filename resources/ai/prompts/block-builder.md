# Templite: сборка блока

## Задача
{{task}}

## Сценарий — выполняй шаги строго по порядку

### 1. Изучи контекст
- `get_block_guide` — конвенции вёрстки блока: разрешённые директивы Blade, вывод полей по типам (`output_by_type`), SCSS-токены, правила JS. Читай перед первой правкой кода.
- `list_block_types` — существующие блоки (образцы стиля и именования) и категории (`categories`) для `block_type_id`.
- `get_block_code {block_id}` — код и поля похожего блока, чтобы перенять стиль.
- `list_global_fields` — если блок выводит общие данные сайта (`$global['ключ']`): ключ должен существовать.
- `list_pages` / `get_page {page_id}` — найди целевую страницу.

### 2. Уточни (если нужно)
Спроси пользователя о неясном:
- Сколько элементов? Фиксированный набор или повторитель (`array`)?
- Нужны иконка или картинка?
- Есть кнопка или ссылка?
- На какую страницу ставить?
- Нужна серверная логика (форма, внешний API, выборка данных)? Тогда понадобится action.

### 3. Спроектируй
До создания распиши:
- поля и их типы — плоский упорядоченный список, вложенность только внутри повторителя `array`;
- Blade-шаблон, SCSS (БЭМ от slug блока), JS при необходимости;
- нужна ли сторонняя библиотека (слайдер, анимации) и action.

### 4. Создай тип блока
```
create_block_type { name: "...", slug: "...", block_type_id: <категория из list_block_types>, description: "..." }
```
Затем поля — по одному вызову на поле:
```
add_block_field { block_id: <id>, name: "Заголовок", key: "title", type: "text" }
add_block_field { block_id: <id>, name: "Карточки", key: "items", type: "array" }   → id поля
add_block_field { block_id: <id>, name: "Название", key: "title", type: "text", parent_id: <id поля items> }
```

### 5. Напиши код
Сначала проверь черновик без сохранения:
```
preview_block { block_id: <id>, template: "<Blade>", style: "<SCSS>", script: "<JS>" }
```
Отказ валидатора — убирай ровно те конструкции, которые он назвал. После успешного рендера сохрани:
```
update_block_code { block_id: <id>, template: "<Blade>", style: "<SCSS>", script: "<JS>" }
```

### 6. Пресет по умолчанию
Обязательно — иначе блок, добавленный из каталога вручную, придёт пустым:
```
create_block_preset { block_id: <id>, name: "По умолчанию", type: "local", is_default: true, data: { ...реалистичный контент по всем полям... } }
```

### 7. Библиотеки и action (если нужны)
- Библиотека: `list_libraries` (может уже быть) → `search_cdn_libraries` → `add_cdn_library` → `attach_library { library_id, target: "block", target_id: <id блока> }`. Подробности — `get_assets_guide`.
- Action: `get_action_guide` → `list_actions` (может уже быть) → `create_action` → `test_action` → `attach_action { action_id, block_id }`.

### 8. Поставь на страницу
```
add_block_to_page { page_id: <id>, block_id: <id>, data: { "title": "...", ... } }
```
Без `data` блок заполнится пресетом по умолчанию. Если к блоку подключён action — выключи кэш экземпляра: `set_block_cache { page_block_id: <из get_page>, enabled: false }`.

### 9. Проверь
- `get_page {page_id}` — блок на странице, данные на месте?
- `get_block_code {block_id}` — код и поля сохранены?

## Конвенции
- **Ключи полей:** `snake_case` (`button_text`, `bg_image`).
- **Slug:** латиница через дефис (`hero-banner`, `advantages`).
- **Категория блока:** только из `list_block_types` (`categories`), не угадывай id.
- **Всегда указывай `description`** при создании блока.
- **Blade:** `{{ $fields['key'] }}`, `<x-cms::image :file="$fields['img']" />`; вывод остальных типов — `get_block_guide`.
- **Повторители:** `@foreach($fields['items'] as $item)`, дочерние ключи — только внутри `$item`.
- **SCSS:** БЭМ `.block-slug { &__element {} }`, цвета — токены из `get_block_guide`.
- **Шапка и подвал** — не блоки, а компоненты `site-header` / `site-footer`.

## Частые ошибки
- Блок без `description`.
- Код до создания типа блока и полей.
- Сохранение кода без `preview_block`.
- Не посмотрел существующие блоки как образец.
- Кириллица в slug.
- Забыт пресет по умолчанию (`create_block_preset`, `is_default: true`).
- Забыт `add_block_to_page` после создания блока.
- Блок с action оставлен в кэше — нужен `set_block_cache` с `enabled: false`.
