<?php

namespace Templite\Cms\Services\Ai\Tools;

use Illuminate\Support\Facades\DB;
use Templite\Cms\Http\Controllers\Api\GlobalSettingsController;
use Templite\Cms\Models\GlobalField;
use Templite\Cms\Models\GlobalFieldPage;
use Templite\Cms\Models\GlobalFieldSection;
use Templite\Cms\Services\Ai\EditorContext;
use Templite\Cms\Services\Ai\Settings\GlobalValueWriter;
use Templite\Cms\Services\Ai\Settings\SettingsFieldDataMerger;
use Templite\Cms\Services\Ai\Settings\SettingsKeyRules;
use Templite\Cms\Services\Ai\Settings\SettingsTree;
use Templite\Cms\Services\CacheManager;

/**
 * Сборка вкладки глобальных настроек одним вызовом: колонки, их ширины,
 * секции и поля целым деревом.
 *
 * Гранулярными вызовами вкладка из трёх секций и десяти полей стоит
 * четырнадцати шагов при дефолтном лимите в двенадцать (AiKey::stepLimit(),
 * его читает AgentRunner) — агент упирается в лимит на середине и оставляет
 * полустраницу.
 *
 * Только upsert: вкладка ищется по имени, секция — по имени внутри вкладки,
 * поле — по ключу. Отсутствующее в дереве не трогается. Удаления здесь нет
 * намеренно — поле уносит с собой заполненные значения, такое решение
 * принимает человек через delete_settings_node.
 */
class ApplySettingsLayoutTool implements Tool
{
    use ChecksSettingsPermission;

    public function __construct(
        private readonly SettingsKeyRules $rules,
        private readonly SettingsTree $tree,
        private readonly CacheManager $cache,
        private readonly GlobalValueWriter $writer,
        // Пишет в аудит-лог тем же методом, что и ручные правки через API —
        // формат записи (action/entity/data) не должен расслаиваться в
        // зависимости от того, кто её сделал, человек или агент.
        private readonly GlobalSettingsController $auditLog,
    ) {
    }

    public function name(): string
    {
        return 'apply_settings_layout';
    }

    public function definition(): array
    {
        $field = [
            'type' => 'object',
            'properties' => [
                'key' => ['type' => 'string', 'description' => 'snake_case: site_phone. Ключ уникален по всему сайту, включая вложенные поля повторителей: при записи значений ключи резолвятся плоской картой ключ → id, поэтому одинаковых имён не бывает даже у полей разных повторителей — ссылкам в двух повторителях давай socials_url и partner_url, а не url в обоих'],
                'name' => ['type' => 'string', 'description' => 'Подпись поля в админке'],
                'type' => ['type' => 'string', 'enum' => SettingsKeyRules::TYPES],
                'hint' => ['type' => 'string', 'description' => 'Подсказка под полем'],
                'required' => ['type' => 'boolean'],
                'options' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Варианты для select, radio, checkbox'],
                'default_value' => ['type' => 'string'],
            ],
            'required' => ['key', 'name', 'type'],
        ];

        $fieldWithChildren = $field;
        $fieldWithChildren['properties']['children'] = [
            'type' => 'array',
            'description' => 'Вложенные поля — только для типа array (повторителя)',
            'items' => $field,
        ];

        return [
            'type' => 'object',
            'properties' => [
                'page' => [
                    'type' => 'object',
                    'properties' => [
                        'name' => ['type' => 'string', 'description' => 'Название вкладки: Контакты'],
                        'columns' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 4],
                        'column_widths' => [
                            'type' => 'array',
                            'items' => ['type' => 'number'],
                            'description' => 'Ширины колонок в процентах, по числу колонок: [60, 40]',
                        ],
                    ],
                    'required' => ['name'],
                ],
                'sections' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'name' => ['type' => 'string'],
                            'column' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 3, 'description' => 'Номер колонки с нуля'],
                            'fields' => ['type' => 'array', 'items' => $fieldWithChildren],
                        ],
                        'required' => ['name'],
                    ],
                ],
            ],
            'required' => ['page', 'sections'],
        ];
    }

    public function execute(array $args, ?EditorContext $ctx): array
    {
        if ($deny = $this->denyIfNoSettingsPermission('settings.edit')) {
            return $deny;
        }

        $pageArgs = $args['page'] ?? null;

        if (! is_array($pageArgs) || ! ($pageArgs['name'] ?? null)) {
            return ['error' => 'validation_failed', 'details' => ['page.name' => ['Название вкладки обязательно.']]];
        }

        $errors = $this->validateTree($args['sections'] ?? []);

        if ($errors) {
            return ['error' => 'validation_failed', 'details' => $errors];
        }

        $changes = ['created' => [], 'updated' => [], 'moved' => []];

        $page = DB::transaction(function () use ($pageArgs, $args, &$changes) {
            $page = GlobalFieldPage::where('name', $pageArgs['name'])->first();

            if (! $page) {
                $page = GlobalFieldPage::create([
                    'name' => $pageArgs['name'],
                    'order' => (int) GlobalFieldPage::max('order') + 1,
                    'columns' => $pageArgs['columns'] ?? 1,
                    'column_widths' => $pageArgs['column_widths'] ?? null,
                ]);
                $changes['created'][] = 'вкладка «'.$page->name.'»';
                $this->auditLog->logAction('create', 'global_settings', $page->id, ['name' => $page->name, 'entity' => 'page']);
            } else {
                $page->update(array_filter([
                    'columns' => $pageArgs['columns'] ?? null,
                    'column_widths' => $pageArgs['column_widths'] ?? null,
                ], fn ($v) => $v !== null));
                $changes['updated'][] = 'вкладка «'.$page->name.'»';
                $this->auditLog->logAction('update', 'global_settings', $page->id, ['name' => $page->name, 'entity' => 'page']);
            }

            foreach ($args['sections'] ?? [] as $sectionIndex => $sectionArgs) {
                $section = GlobalFieldSection::where('global_field_page_id', $page->id)
                    ->where('name', $sectionArgs['name'])
                    ->first();

                $column = (int) ($sectionArgs['column'] ?? 0);

                if (! $section) {
                    $section = GlobalFieldSection::create([
                        'name' => $sectionArgs['name'],
                        'global_field_page_id' => $page->id,
                        'order' => $sectionIndex,
                        'column_index' => $column,
                    ]);
                    $changes['created'][] = 'секция «'.$section->name.'»';
                    $this->auditLog->logAction('create', 'global_settings', $section->id, ['name' => $section->name, 'entity' => 'section']);
                } else {
                    $movedColumn = (int) ($section->column_index ?? 0) !== $column;
                    $section->update(['order' => $sectionIndex, 'column_index' => $column]);
                    $changes[$movedColumn ? 'moved' : 'updated'][] = 'секция «'.$section->name.'»';
                    // В контроллере «перенос в другую колонку» и «обычная правка» —
                    // один и тот же updateSection(), поэтому и в журнале это одно
                    // и то же действие update: 'moved' — метка только для changes,
                    // которую видит агент, аудит-лог её не знает.
                    $this->auditLog->logAction('update', 'global_settings', $section->id, ['name' => $section->name, 'entity' => 'section']);
                }

                foreach ($sectionArgs['fields'] ?? [] as $fieldIndex => $fieldArgs) {
                    $field = $this->upsertField($fieldArgs, $page->id, $section->id, null, $fieldIndex, $changes);

                    foreach ($fieldArgs['children'] ?? [] as $childIndex => $childArgs) {
                        $this->upsertField($childArgs, $page->id, $section->id, $field->id, $childIndex, $changes);
                    }
                }
            }

            return $page;
        });

        // Структурные правки кэш $global не сбрасывают: invalidateGlobalFields()
        // зовётся только из saveValues(). Без этого новое поле с дефолтом
        // не появится на публичной странице до истечения часового TTL.
        $this->cache->invalidateGlobalFields();

        $built = $this->tree->build($page->id, false);

        $result = [
            'status' => 'applied',
            'page_id' => $page->id,
            'layout' => $built['pages'][0]['layout'] ?? '',
            'changes' => array_filter($changes),
        ];

        // Структурная правка ушла прямо в базу, и оба экрана настроек после неё
        // показывают устаревшее: сигнал один на оба, а что именно перечитать,
        // решает сам экран (структуру или структуру вместе со значениями).
        if ($ctx && $ctx->isSettingsEditorOpen()) {
            $result['editor_op'] = ['op' => 'refresh_settings', 'payload' => ['page_id' => $page->id]];
        }

        return $result;
    }

    /**
     * @param  array<int,array<string,mixed>>  $sections
     * @return array<string,array<int,string>>
     */
    private function validateTree(array $sections): array
    {
        $errors = [];
        $seen = [];

        foreach ($sections as $sectionArgs) {
            foreach ($sectionArgs['fields'] ?? [] as $fieldArgs) {
                foreach (array_merge([$fieldArgs], $fieldArgs['children'] ?? []) as $depth => $candidate) {
                    $key = (string) ($candidate['key'] ?? '');
                    $parentKey = $depth === 0 ? null : (string) ($fieldArgs['key'] ?? '');
                    $seenKey = $parentKey ? $parentKey.'.'.$key : $key;

                    // Ключ уникален по всему сайту, поэтому и в дереве он
                    // считается по голому имени: два повторителя с детьми
                    // 'url' — тот самый случай, ради которого правило и есть.
                    if (isset($seen[$key])) {
                        $errors['fields.'.$seenKey][] = 'Ключ «'.$key.'» уже встречается в этом дереве. Ключ уникален по всему сайту, включая вложенные поля повторителей: при записи значений ключи резолвятся плоской картой ключ → id, поэтому одинаковых имён не бывает даже у полей разных повторителей.';

                        continue;
                    }

                    $seen[$key] = true;

                    $parentId = $parentKey
                        ? optional(GlobalField::where('key', $parentKey)->whereNull('parent_id')->first())->id
                        : null;

                    // Дубль ищется без оглядки на родителя — так же, как его
                    // потом ищет SettingsKeyRules. Самим собой поле считается
                    // только если совпал и родитель: иначе корневой 'url'
                    // прикрыл бы собой попытку завести 'url' внутри
                    // повторителя, а upsertField всё равно создал бы второе
                    // поле с тем же ключом.
                    $existing = GlobalField::where('key', $key)->first();
                    $isSameField = $existing && (int) $existing->parent_id === (int) $parentId;

                    foreach ($this->rules->validateKey($key, $isSameField ? $existing->id : null) as $messages) {
                        foreach ($messages as $message) {
                            $errors['fields.'.$seenKey][] = $message;
                        }
                    }

                    $type = (string) ($candidate['type'] ?? '');

                    foreach ($this->rules->validateType($type) as $messages) {
                        foreach ($messages as $message) {
                            $errors['fields.'.$seenKey][] = $message;
                        }
                    }

                    // Значение по умолчанию у поля img/file — тот же id файла,
                    // что и обычное значение, поэтому и карантин ai_pending на
                    // нём тот же. Берём итоговый default: если ключа в дереве
                    // нет, upsertField оставит старый, а type при этом мог
                    // смениться на файловый — двухшаговый обход закрывается
                    // только сверкой обоих итоговых значений.
                    $default = array_key_exists('default_value', $candidate)
                        ? $candidate['default_value']
                        : ($isSameField ? $existing->default_value : null);

                    foreach ($this->writer->validateDefault($type, $default) as $message) {
                        $errors['fields.'.$seenKey][] = 'default_value: '.$message;
                    }

                    if ($depth > 0 && ($fieldArgs['type'] ?? '') !== 'array') {
                        $errors['fields.'.$seenKey][] = 'Вложенные поля бывают только у поля типа array.';
                    }

                    if ($depth > 0 && ! empty($candidate['children'])) {
                        $errors['fields.'.$seenKey][] = 'Повторитель внутри повторителя не поддерживается: значения такого поля не сохранятся и не отрендерятся.';
                    }
                }
            }
        }

        return $errors;
    }

    /**
     * @param  array<string,mixed>  $args
     * @param  array<string,array<int,string>>  $changes
     */
    private function upsertField(array $args, int $pageId, int $sectionId, ?int $parentId, int $order, array &$changes): GlobalField
    {
        $field = GlobalField::where('key', $args['key'])->where('parent_id', $parentId)->first();

        if (! $field) {
            $data = array_filter([
                'hint' => $args['hint'] ?? null,
                'required' => ($args['required'] ?? false) ? true : null,
                'options' => $args['options'] ?? null,
            ], fn ($value) => $value !== null);

            $field = GlobalField::create([
                'key' => $args['key'],
                'parent_id' => $parentId,
                'name' => $args['name'],
                'type' => $args['type'],
                'default_value' => $args['default_value'] ?? null,
                'data' => $data ?: null,
                'global_field_page_id' => $pageId,
                'global_field_section_id' => $sectionId,
                'order' => $order,
            ]);
            $changes['created'][] = 'поле '.$field->key;
            $this->auditLog->logAction('create', 'global_settings', $field->id, ['name' => $field->name, 'key' => $field->key, 'entity' => 'field']);

            return $field;
        }

        // Дерево, которое агент передаёт в этот инструмент, не обязано быть
        // полным: докблок класса обещает "только upsert, отсутствующее в дереве
        // не трогается". Раньше это правило соблюдалось на уровне вкладок и
        // секций, но не атрибутов поля — data и default_value перезаписывались
        // целиком, поэтому повторное применение того же дерева без hint
        // молча стирало подсказку, написанную человеком в админке. Здесь —
        // слияние: ключ, которого нет в $args, оставляет старое значение;
        // ключ, который есть (array_key_exists, в том числе с "пустым"
        // значением вроде false/[]), перезаписывает его. Логика слияния общая
        // с UpdateSettingsFieldTool — вынесена в SettingsFieldDataMerger,
        // чтобы два инструмента не могли снова разъехаться в семантике.
        $data = SettingsFieldDataMerger::merge($field->data ?? [], $args);

        $attributes = [
            'name' => $args['name'],
            'type' => $args['type'],
            'data' => $data ?: null,
            'global_field_page_id' => $pageId,
            'global_field_section_id' => $sectionId,
            'order' => $order,
        ];

        // default_value — как и hint/required/options: трогаем только если ключ
        // реально пришёл в аргументах, иначе значение по умолчанию, заданное
        // раньше (человеком или прошлым вызовом), не должно обнуляться.
        if (array_key_exists('default_value', $args)) {
            $attributes['default_value'] = $args['default_value'];
        }

        $movedSection = (int) $field->global_field_section_id !== $sectionId;
        $field->update($attributes);
        $changes[$movedSection ? 'moved' : 'updated'][] = 'поле '.$field->key;
        // Как и у секции: перенос в другую секцию — та же update-операция,
        // что и обычная правка поля, с точки зрения updateField() в контроллере.
        $this->auditLog->logAction('update', 'global_settings', $field->id, ['name' => $field->name, 'key' => $field->key, 'entity' => 'field']);

        return $field;
    }
}
