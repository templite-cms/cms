<?php

return [
    // URL админки
    'admin_url' => env('CMS_ADMIN_URL', 'cms'),

    // Название CMS
    'name' => env('CMS_NAME', 'Templite CMS'),

    // Стандартные размеры изображений (fallback, если не указаны в поле)
    'default_image_sizes' => [
        'thumb' => ['width' => 150, 'height' => 150, 'fit' => 'crop'],
        'small' => ['width' => 300, 'height' => null, 'fit' => 'contain'],
        'medium' => ['width' => 600, 'height' => null, 'fit' => 'contain'],
        'large' => ['width' => 1200, 'height' => null, 'fit' => 'contain'],
    ],

    // Размеры для скриншотов блоков
    'block_screenshot_sizes' => [
        'thumb' => ['width' => 300, 'height' => 200, 'fit' => 'cover'],
        'medium' => ['width' => 600, 'height' => null, 'fit' => 'contain'],
    ],

    // Размеры для скриншотов страниц
    'page_screenshot_sizes' => [
        'thumb' => ['width' => 400, 'height' => 225, 'fit' => 'cover'],
        'medium' => ['width' => 960, 'height' => null, 'fit' => 'contain'],
    ],

    // Форматы конвертации по умолчанию
    'default_image_formats' => ['original', 'webp'],

    // Качество сжатия по умолчанию
    'default_image_quality' => 85,

    // Максимальный размер загрузки (MB)
    'max_upload_size' => 20,

    // Санитизация SVG при загрузке (удаление скриптов, event-handler'ов)
    'sanitize_svg' => env('CMS_SANITIZE_SVG', true),

    // Допустимые типы файлов
    'allowed_file_types' => [
        'image' => ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg'],
        'video' => ['mp4', 'webm', 'avi'],
        'document' => ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'txt', 'csv'],
        'archive' => ['zip', 'rar', '7z', 'tar', 'gz'],
    ],

    // TASK-S14 (M-07): Запрещённые расширения файлов — исполняемые и серверные скрипты.
    // Блокируются независимо от MIME-типа.
    'blocked_file_extensions' => [
        'php', 'php3', 'php4', 'php5', 'php7', 'php8', 'phtml', 'phar',
        'sh', 'bash', 'csh', 'ksh', 'zsh',
        'exe', 'com', 'bat', 'cmd', 'msi', 'scr', 'pif',
        'pl', 'cgi', 'py', 'rb', 'jsp', 'asp', 'aspx',
        'htaccess', 'htpasswd',
    ],

    // Кэширование
    'cache' => [
        'enabled' => env('CMS_CACHE_ENABLED', true),
        'driver' => env('CMS_CACHE_DRIVER', 'file'),
        'ttl' => env('CMS_CACHE_TTL', 86400), // 24 часа
    ],

    // Очередь для обработки изображений
    'image_queue' => env('CMS_IMAGE_QUEUE', 'images'),

    // Dev-mode (показывает файловый менеджер проекта)
    'dev_mode' => env('CMS_DEV_MODE', false),

    // TASK-S17 (M-09): Полный список допустимых permissions.
    // Используется для валидации personal_permissions и permissions типов менеджеров.
    // При наличии ModuleRegistry динамический список модулей имеет приоритет.
    // Этот конфиг — fallback и статический источник истины.
    // Wildcard-значения '*' и 'group.*' обрабатываются отдельно в middleware.
    'permissions' => [
        // --- CMS: Контент ---
        'pages.view',
        'pages.create',
        'pages.edit',
        'pages.delete',
        'page_types.view',
        'page_types.create',
        'page_types.edit',
        'page_types.delete',
        // --- CMS: Конструктор ---
        'blocks.view',
        'blocks.create',
        'blocks.edit',
        'blocks.delete',
        'block_types.view',
        'block_types.create',
        'block_types.edit',
        'block_types.delete',
        'actions.view',
        'actions.create',
        'actions.edit',
        'actions.delete',
        'actions.code',
        'components.view',
        'components.create',
        'components.edit',
        'components.delete',
        'templates.view',
        'templates.create',
        'templates.edit',
        'templates.delete',
        // --- CMS: Медиа ---
        'media.view',
        'media.upload',
        'media.edit',
        'media.delete',
        'file_manager.view',
        'file_manager.edit',
        // --- CMS: Настройки ---
        'settings.view',
        'settings.edit',
        'managers.view',
        'managers.create',
        'managers.edit',
        'managers.delete',
        'manager_types.view',
        'manager_types.create',
        'manager_types.edit',
        'manager_types.delete',
        'logs.view',
    ],

    // TASK-S01: Honeypot anti-bot защита для публичных action-эндпоинтов
    'honeypot' => [
        'enabled' => env('CMS_HONEYPOT_ENABLED', true),
        'field' => '_hp_name',       // Имя скрытого поля-ловушки
        'time_field' => '_hp_time',  // Имя поля с timestamp создания формы
        'min_time' => 2,             // Минимальное время заполнения формы (секунды)
    ],

    // TASK-S13 (M-04): Заголовки безопасности для публичных маршрутов.
    // Content-Security-Policy, X-Content-Type-Options, X-Frame-Options, Referrer-Policy.
    'security_headers' => [
        'enabled' => env('CMS_SECURITY_HEADERS_ENABLED', true),

        // CSP-директивы (ключ — имя директивы, значение — правила).
        // Пустая строка или null — директива не добавляется.
        'csp' => [
            'default-src' => "'self'",
            'script-src'  => "'self'",
            'style-src'   => "'self' 'unsafe-inline'",
            'img-src'     => "'self' data:",
            'font-src'    => "'self'",
            'connect-src' => "'self'",
            'frame-ancestors' => "'none'",
        ],

        'x_content_type_options' => 'nosniff',
        'x_frame_options' => 'DENY',
        'referrer_policy' => 'strict-origin-when-cross-origin',

        // Permissions-Policy (null = не добавлять)
        'permissions_policy' => null,
    ],

    // Двухфакторная аутентификация
    'two_factor' => [
        // Режим: 'off' — выключено, 'optional' — по желанию, 'required' — обязательно
        'mode' => env('CMS_TWO_FACTOR_MODE', 'off'),

        // Доверие устройству: 0 — всегда спрашивать, N — доверять N дней
        'trust_days' => (int) env('CMS_TWO_FACTOR_TRUST_DAYS', 0),

        // TOTP window (количество 30-сек интервалов для допуска): 1 = ±30 сек
        'totp_window' => 1,

        // Cookie-имя для доверенного устройства
        'trust_cookie' => 'cms_trusted_device',
    ],
];
