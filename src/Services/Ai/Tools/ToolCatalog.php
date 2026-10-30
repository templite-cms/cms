<?php

namespace Templite\Cms\Services\Ai\Tools;

/**
 * Метаданные инструментов: описание для модели, ability MCP-токена,
 * входит ли инструмент в профиль content и в каких каналах доступен.
 *
 * Одна таблица вместо методов в каждом классе: так видно весь набор сразу,
 * а реестр падает при старте, если у нового инструмента нет записи.
 */
final class ToolCatalog
{
    public const CHANNEL_ASSISTANT = 'assistant';
    public const CHANNEL_MCP = 'mcp';

    /**
     * имя => [ability, в профиле content, каналы]
     * ability: read — чтение и превью; write — данные и структура; code — Blade/SCSS/JS
     * и PHP actions; delete — удаление.
     *
     * @var array<string,array{0:string,1:bool,2?:array<int,string>}>
     */
    private const META = [
        // Страницы и данные блоков
        'list_pages' => ['read', true],
        'get_page' => ['read', true],
        'create_page' => ['write', true],
        'update_page' => ['write', true],
        'add_block_to_page' => ['write', true],
        'update_block_data' => ['write', true],
        'set_block_cache' => ['write', true],
        // Типы блоков
        'list_block_types' => ['read', true],
        'get_block_code' => ['read', false],
        // Превью рендерит присланный Blade — это исполнение кода, не чтение
        'preview_block' => ['code', false],
        'create_block_type' => ['write', false],
        'add_block_field' => ['write', false],
        'update_block_field' => ['write', false],
        'update_block_code' => ['code', false],
        'restore_block_code_version' => ['code', false],
        // Пресеты
        'list_block_presets' => ['read', true],
        'get_block_preset' => ['read', true],
        'create_block_preset' => ['write', true],
        'update_block_preset' => ['write', true],
        'apply_preset_to_block' => ['write', true],
        // Медиа
        'list_media' => ['read', true],
        'list_media_folders' => ['read', true],
        'upload_media_from_url' => ['write', true],
        'create_media_folder' => ['write', true],
        'update_media_file' => ['write', true],
        // Подтверждение сгенерированной картинки есть только в чате ассистента
        'generate_image' => ['write', true, [self::CHANNEL_ASSISTANT]],
        // Библиотеки
        'search_cdn_libraries' => ['read', false],
        'add_cdn_library' => ['write', false],
        'list_libraries' => ['read', false],
        'attach_library' => ['write', false],
        // Actions
        'get_action_guide' => ['read', false],
        'list_actions' => ['read', false],
        'get_action_code' => ['read', false],
        'create_action' => ['code', false],
        'update_action_code' => ['code', false],
        'attach_action' => ['write', false],
        'set_action_params' => ['write', true],
        'test_action' => ['code', false],
        // Шаблоны
        'list_templates' => ['read', true],
        'create_template' => ['code', false],
        'get_template_code' => ['read', false],
        'update_template_code' => ['code', false],
        'preview_template' => ['code', false],
        'add_template_field' => ['write', false],
        // Компоненты
        'list_components' => ['read', false],
        'get_component_code' => ['read', false],
        'create_component' => ['code', false],
        'update_component_code' => ['code', false],
        'preview_component' => ['code', false],
        // Справки
        'get_block_guide' => ['read', false],
        'get_template_guide' => ['read', false],
        'get_component_guide' => ['read', false],
        'get_media_guide' => ['read', true],
        'get_assets_guide' => ['read', false],
        'get_settings_guide' => ['read', true],
        // Глобальные настройки
        'list_global_fields' => ['read', true],
        'apply_settings_layout' => ['write', false],
        'update_settings_field' => ['write', false],
        'delete_settings_node' => ['delete', false],
        'set_global_values' => ['write', true],
    ];

    /** @var array<string,string> */
    private const DESCRIPTIONS = [
        'list_pages' => 'Список страниц сайта (id, title, url, status).',
        'get_page' => 'Прочитать страницу и её блоки (с данными, которые выводятся). linked_preset у блока — связь с глобальным пресетом: блок показывает данные пресета; отвязать — apply_preset_to_block с unlink: true. Для открытой в редакторе — live-версия.',
        'list_block_types' => 'Доступные типы блоков и схемы их полей.',
        'create_page' => 'Создать новую страницу сайта.',
        'add_block_to_page' => 'Добавить блок на страницу (или предложить в открытый редактор). preset_id — добавить с пресетом из list_block_presets. Без data и preset_id блок заполнится пресетом по умолчанию, если он есть, иначе останется пустым — передавай реальный контент.',
        'update_block_data' => 'Обновить данные блока на странице (или предложить правку в открытый редактор). data заменяет данные блока целиком — передавай все поля, не только изменённые. Блок со связью linked_preset выводит данные глобального пресета: его данные не правятся — правь пресет (update_block_preset) или отвяжи блок (apply_preset_to_block).',
        'list_block_presets' => 'Пресеты типа блока: id, название, local/global, какой по умолчанию, у глобальных — сколько блоков с ним связано. Без данных — данные отдаёт get_block_preset.',
        'get_block_preset' => 'Пресет целиком с данными. У глобального — used_on: страницы, где блоки с ним связаны.',
        'create_block_preset' => 'Создать пресет блока. Данные — data по схеме полей или from_page_block_id (сохранить блок со страницы как пресет). local копирует данные в блок, global связывает; is_default — стартовый контент блока, только local.',
        'update_block_preset' => 'Изменить пресет. data заменяет данные целиком. Правка данных глобального пресета со связанными блоками без confirm: true не записывается и возвращает список страниц — покажи его пользователю и повтори с confirm: true только после его согласия.',
        'apply_preset_to_block' => 'Пресет у блока на странице: preset_id локального — скопировать данные в блок; preset_id глобального — связать блок с пресетом; unlink: true — отвязать, данные пресета станут данными блока.',
        'get_block_code' => 'Прочитать код блока (Blade, SCSS, JS) и схему его полей. Используй, чтобы перенять стиль существующих блоков перед созданием нового.',
        'preview_block' => 'Отрендерить код блока БЕЗ сохранения: вернёт html либо ok=false с текстом ошибки. Всегда проверяй черновик этим инструментом перед update_block_code.',
        'create_block_type' => 'Создать новый тип блока (пустой, без кода и полей).',
        'add_block_field' => 'Добавить поле в тип блока. Ключ — snake_case. Для повторяющихся элементов создай поле типа array и вложи в него поля через parent_id.',
        'update_block_field' => 'Изменить существующее поле блока: название, тип, подсказку. Ключ поля менять нельзя. Значения по умолчанию у полей нет — стартовый контент задаёт пресет блока.',
        'update_block_code' => 'Сохранить код блока. Если открыт редактор этого блока — правка уйдёт туда на подтверждение пользователю.',
        'restore_block_code_version' => 'Откатить код блока к сохранённой версии из истории.',
        'list_media' => 'Файлы медиабиблиотеки: поиск по имени, фильтр по типу и папке. Значение для полей img и file — это id отсюда.',
        'list_media_folders' => 'Папки медиабиблиотеки.',
        'upload_media_from_url' => 'Скачать файл по прямой ссылке и добавить в медиабиблиотеку.',
        'create_media_folder' => 'Создать папку в медиабиблиотеке.',
        'update_media_file' => 'Переименовать файл, задать alt/title или переместить в другую папку.',
        'generate_image' => 'Сгенерировать изображение по описанию и сохранить в медиабиблиотеку. Платная операция: по одному изображению за раз, без вариантов «на выбор».',
        'search_cdn_libraries' => 'Найти библиотеку в каталоге cdnjs. Без package — поиск по названию; с package — список файлов версии. Всегда смотри файлы перед добавлением, не угадывай имена. Ошибка catalog_unavailable означает, что каталог сейчас недоступен: повторять запрос с другими параметрами бессмысленно.',
        'add_cdn_library' => 'Создать библиотеку CMS из пакета cdnjs. mode=link подключает ссылками на CDN, mode=download скачивает файлы на сервер. По умолчанию link. Ошибка catalog_unavailable означает, что каталог сейчас недоступен: повторять запрос с другими параметрами бессмысленно. Созданная библиотека ни к чему не подключена — подключи её через attach_library.',
        'list_libraries' => 'Библиотеки CMS: какие есть, из каких файлов состоят и к скольким блокам и шаблонам подключены. Смотри сюда перед add_cdn_library — нужная библиотека может уже быть заведена.',
        'list_templates' => 'Шаблоны страниц и подключённые к ним библиотеки.',
        'attach_library' => 'Подключить библиотеку к типу блока или к шаблону (action=detach — отключить). Правка точечная: остальные подключения не трогаются. Библиотека в блоке нужна, когда его вёрстка опирается на чужой js или css; общий для всего сайта набор подключают к шаблону.',
        'get_action_guide' => 'Как писать код action: шаблон класса, поля контекста, список разрешённых функций и классов. Читай это перед create_action и update_action_code — валидатор отклоняет всё, чего нет в белом списке.',
        'list_actions' => 'Actions сайта: что делают, какие параметры принимают (params), что возвращают (returns) и к каким типам блоков подключены. Смотри сюда перед create_action — нужный action может уже быть.',
        'get_action_code' => 'Прочитать PHP-код action. Для открытого в редакторе — несохранённую версию из формы. Поле editable=false означает, что код лежит в app или в пакете и правке через CMS не поддаётся.',
        'create_action' => 'Создать action — серверную логику для блока. С полем code сразу сохраняет и проверяет PHP; без него создаётся пустая запись. Перед вызовом прочитай get_action_guide. Созданный action ни к какому блоку не подключён — подключи его через attach_action.',
        'update_action_code' => 'Сохранить PHP-код action. Отказ валидатора приходит списком строк — исправляй по нему. Если открыт редактор этого action, правка уйдёт туда на подтверждение пользователю.',
        'attach_action' => 'Подключить action к типу блока (action=detach — отключить). Без этого код action не выполнится ни на одной странице. params задают настройки для всех экземпляров блока, order — очерёдность выполнения. Правка точечная: остальные подключения не трогаются.',
        'set_block_cache' => 'Включить или выключить кэш HTML одного блока на странице. Блок, к типу которого подключён action, держи без кэша — иначе результат action закэшируется и перестанет обновляться. Ajax-вызовы action (/action/{slug} из JS) к блоку не подключаются и кэшу не мешают.',
        'set_action_params' => 'Задать параметры action для одного блока на конкретной странице — поверх параметров привязки. Соседние actions того же блока не трогаются. Для настроек, общих всем экземплярам блока, используй params в attach_action.',
        'test_action' => 'Выполнить action на странице и посмотреть, что он вернул. Проверяй так каждый только что написанный код — как preview_block для вёрстки. Результат обрезается по объёму, ключи возвращаются целиком.',
        'create_template' => 'Создать шаблон страницы — общую обёртку: шапку, подвал, подключение стилей. С полями template/style/script сразу сохраняет код. Сам по себе шаблон ни на одну страницу не встанет: назначь его через update_page.',
        'get_template_code' => 'Прочитать Blade, SCSS и JS шаблона и список его полей. Для открытого в редакторе — несохранённую версию из формы. Смотри сюда перед правкой, чтобы не затереть чужую вёрстку.',
        'update_template_code' => 'Сохранить код шаблона. Передавай только те части, которые меняешь: пустая строка в style или script удаляет файл. Сначала проверь черновик через preview_template.',
        'preview_template' => 'Отрендерить шаблон БЕЗ сохранения: вернёт html либо ok=false с текстом ошибки. Так же, как preview_block для вёрстки блока.',
        'add_template_field' => 'Добавить поле шаблона — настройку, которая отличается между шаблонами. Логотип, телефоны, контакты и соцсети сюда не клади: это глобальные поля (apply_settings_layout). В Blade шаблона доступно как $fields[\'ключ\'].',
        'update_page' => 'Изменить саму страницу: заголовок, адрес, шаблон, публикацию. Смена alias меняет адрес — старые ссылки перестанут работать. Блоки страницы правят update_block_data и add_block_to_page.',
        'list_components' => 'Blade-компоненты сайта: переиспользуемые куски вёрстки для блоков и шаблонов — шапка, подвал, карточка. Показывает тег вызова и параметры. Смотри сюда перед тем, как верстать шапку или подвал заново.',
        'get_component_code' => 'Прочитать разметку, стили и скрипт компонента. Поле editable=false означает, что файлы лежат в app или в пакете и правке через CMS не поддаются.',
        'create_component' => 'Создать Blade-компонент. С полями template/style/script сразу сохраняет файлы. Вызывается в вёрстке тегом <x-cms::slug />, стили и скрипты подключаются сами.',
        'update_component_code' => 'Сохранить код компонента. Правка отразится везде, где он используется. Сначала проверь черновик через preview_component.',
        'preview_component' => 'Отрендерить компонент БЕЗ сохранения: вернёт html либо ok=false с текстом ошибки.',
        'get_block_guide' => 'Конвенции вёрстки блока: разрешённые директивы Blade, ключи и типы полей, БЭМ и цветовые токены SCSS, как пишется JS блока. Читай перед первой правкой кода блока — валидатор отклоняет всё, что вне этих правил.',
        'get_template_guide' => 'Конвенции шаблона страницы: чем подставляются блоки, какие переменные доступны в Blade, как шаблон встаёт на страницу. Читай перед create_template и update_template_code.',
        'get_component_guide' => 'Конвенции Blade-компонентов: как зовётся, как принимает параметры, что подключается само. Читай перед create_component и update_component_code.',
        'get_media_guide' => 'Работа с файлами: что класть в поля типа img и file, как выводить картинки, правила генерации изображений.',
        'get_assets_guide' => 'Что подключается к странице само, а что требует attach_library: собственные стили блока и шаблона, стили компонентов, сторонние библиотеки.',
        'list_global_fields' => 'Глобальные поля сайта: вкладки, секции по колонкам, ключи полей и текущие значения. Читай это перед тем, как вставлять $global[\'ключ\'] в вёрстку, — иначе ключа может не быть и блок отрендерится пустым.',
        'get_settings_guide' => 'Конвенции глобальных полей: чем отличаются от полей шаблона, типы, правила ключей, вывод в Blade и правила раскладки вкладки по колонкам. Читай перед apply_settings_layout.',
        'apply_settings_layout' => 'Собрать вкладку глобальных настроек целиком: колонки, их ширины, секции и поля одним вызовом. Вкладка ищется по имени, секция — по имени, поле — по ключу: совпавшее обновляется и переставляется, новое создаётся, отсутствующее в дереве не трогается. Ничего не удаляет. Перед первым вызовом прочитай get_settings_guide.',
        'update_settings_field' => 'Изменить одно глобальное поле: подпись, тип, подсказку, варианты, значение по умолчанию, секцию. Ключ поля менять нельзя — на него ссылается вёрстка.',
        'delete_settings_node' => 'Удалить вкладку, секцию или поле настроек. Первый вызов без confirm ничего не удаляет — возвращает отчёт: сколько заполненных значений пропадёт и в каких файлах вёрстки используется ключ. Удаляет только повторный вызов с confirm=true. Удаляй только по явной просьбе пользователя.',
        'set_global_values' => 'Заполнить глобальные поля значениями: ключ поля => значение. Повторители — списком объектов. Значения сверяются с типами полей: в img и file кладётся id файла из list_media, в select — только заведённый вариант. Если открыт экран настроек, правка уйдёт туда на подтверждение пользователю.',
    ];

    public function has(string $name): bool
    {
        return isset(self::META[$name], self::DESCRIPTIONS[$name]);
    }

    public function description(string $name): string
    {
        return self::DESCRIPTIONS[$name] ?? $name;
    }

    public function ability(string $name): string
    {
        return self::META[$name][0] ?? 'write';
    }

    public function inContent(string $name): bool
    {
        return self::META[$name][1] ?? false;
    }

    public function inChannel(string $name, string $channel): bool
    {
        $channels = self::META[$name][2] ?? [self::CHANNEL_ASSISTANT, self::CHANNEL_MCP];

        return in_array($channel, $channels, true);
    }
}
