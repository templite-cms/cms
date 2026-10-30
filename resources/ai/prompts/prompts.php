<?php

// Сценарии для MCP-клиентов. Тексты — prompts/{name}.md; приложение может
// заменить любой файлом resources/cms/ai/prompts/{name}.md. Ключ profiles —
// для каких профилей токена сценарий виден (по умолчанию builder и full).
return [
    'block-builder' => [
        'description' => 'Пошаговый сценарий: создать блок и поставить его на страницу',
        'arguments' => [['name' => 'task', 'description' => 'Какой блок нужен, например «преимущества — 4 карточки»', 'required' => true]],
    ],
    'site-pipeline' => [
        'description' => 'Полный цикл создания сайта: требования, дизайн, прототипы, сборка в CMS, проверка',
        'arguments' => [['name' => 'brief', 'description' => 'Описание сайта, например «сайт стоматологии Dental+ в Москве»', 'required' => true]],
    ],
    'backend-decompose' => [
        'description' => 'Разложить HTML-прототипы на сущности CMS: настройки, компоненты, шаблоны, блоки, страницы',
        'arguments' => [],
    ],
    'project-sync' => [
        'description' => 'Собрать сущности CMS и сгенерировать docs/PROJECT.md',
        'arguments' => [],
    ],
    'conventions' => [
        'description' => 'Краткий справочник конвенций Templite: типы полей, именование, Blade, SCSS',
        'arguments' => [],
    ],
    'designer' => [
        'description' => 'Дизайн-бриф сайта (DESIGN_BRIEF.md): цвета, типографика, компоненты',
        'arguments' => [['name' => 'niche', 'description' => 'Ниша сайта, например «стоматология»', 'required' => true]],
    ],
    'frontend-proto' => [
        'description' => 'Самодостаточные HTML-прототипы с разметкой для разбора на сущности CMS',
        'arguments' => [],
    ],
];
