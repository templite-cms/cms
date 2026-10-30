# Templite: HTML-прототипы

Создай HTML-прототипы страниц в `docs/prototypes/` по `docs/PROJECT.md` и `docs/DESIGN_BRIEF.md`.

## Шаги
1. Прочитай `docs/PROJECT.md` (структура, контент) и `docs/DESIGN_BRIEF.md` (визуал).
2. Подготовь базовый CSS: переменные, сброс, сетка, типографика, UI-компоненты.
3. Сверстай шапку и подвал как компоненты `site-header` и `site-footer`.
4. Сделай по одному HTML-файлу на страницу.

## Формат HTML-файла
```html
<!DOCTYPE html>
<html lang="ru">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>{Заголовок страницы}</title>
  <link href="https://fonts.googleapis.com/css2?family={Font}&display=swap" rel="stylesheet">
  <style>
    /* BASE: CSS-переменные, сброс, сетка, типографика — уйдёт в стили шаблона */
    /* COMPONENTS: .site-header, .site-footer — уйдут в стили компонентов */
    /* BLOCKS: .hero-banner, .services-grid и т.д. — уйдут в SCSS блоков */
  </style>
</head>
<body>

<!-- COMPONENT: site-header -->
<header class="site-header">...</header>
<!-- /COMPONENT: site-header -->

<!-- BLOCK: hero-banner -->
<section class="hero-banner">...</section>
<!-- /BLOCK: hero-banner -->

<!-- BLOCK: services-grid -->
<section class="services-grid">...</section>
<!-- /BLOCK: services-grid -->

<!-- COMPONENT: site-footer -->
<footer class="site-footer">...</footer>
<!-- /COMPONENT: site-footer -->

</body>
</html>
```

## Правила
- `<!-- COMPONENT: slug -->` — для переиспользуемого (шапка, подвал), `<!-- BLOCK: slug -->` — для блоков страницы.
- Весь CSS встроен в `<style>`, без внешних файлов (кроме шрифтов).
- БЭМ-классы, корневой класс совпадает со slug блока или компонента.
- Стили блока не должны зависеть от соседних блоков — каждый блок самодостаточная `<section>`.
- Реальный контент из PROJECT.md, никакого Lorem ipsum.
- Повторяющиеся элементы (карточки, пункты) верстай однотипно — они станут повторителем `array`.
- Общие данные (телефон, email, адрес, соцсети, меню) пиши одинаково на всех страницах — они станут глобальными полями.
- Картинки: `https://placehold.co/600x400`.
- Адаптивность через `@media (max-width: 768px)`.
- Только вёрстка и CSS: интерактив, которому нужен сервер (формы, данные из API), помечай комментарием — в CMS это будет action.
