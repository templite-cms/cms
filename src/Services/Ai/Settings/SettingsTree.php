<?php

namespace Templite\Cms\Services\Ai\Settings;

use Templite\Cms\Models\GlobalField;
use Templite\Cms\Models\GlobalFieldPage;
use Templite\Cms\Services\GlobalFieldsResolver;

/**
 * Структура глобальных настроек в виде, пригодном для агента: вкладки с
 * колонками, секции с номером колонки, поля с ключами и значениями.
 *
 * Осиротевшие поля отдаются отдельным списком: удаление вкладки обнуляет
 * global_field_page_id полей (nullOnDelete), из админки они пропадают, а в
 * $global остаются — без этого списка агент их не увидит и заведёт дубль.
 *
 * Аналогично для секций: их удаление (destroySection()) обнуляет только
 * global_field_section_id (тоже nullOnDelete), вкладку не трогает. Такое
 * поле не попадает ни в pages[].sections[].fields (нет секции), ни в
 * orphan_fields (вкладка есть) — оно отдаётся списком detached_fields.
 */
class SettingsTree
{
    /** Значение длиннее обрезается: агенту нужен факт заполненности, а не простыня текста. */
    private const MAX_VALUE_CHARS = 200;

    public function __construct(private readonly GlobalFieldsResolver $resolver)
    {
    }

    /**
     * @param  int|string|null  $pageRef  id или название вкладки; null — все
     * @param  array<string,mixed>  $unsavedValues  несохранённые значения формы экрана /cms/settings
     *                                               (EditorContext::$settingsValues) — накладываются
     *                                               поверх значений из БД, если отличаются
     * @return array<string,mixed>
     */
    public function build(int|string|null $pageRef = null, bool $withValues = true, array $unsavedValues = []): array
    {
        $query = GlobalFieldPage::with([
            'sections' => fn ($q) => $q->orderBy('order'),
            'sections.fields' => fn ($q) => $q->whereNull('parent_id')->orderBy('order'),
            'sections.fields.allChildren',
        ])->orderBy('order');

        if (is_int($pageRef)) {
            $query->where('id', $pageRef);
        } elseif (is_string($pageRef) && $pageRef !== '') {
            $query->where('name', $pageRef);
        }

        $values = $withValues ? $this->resolver->resolve() : [];
        // При закрытой форме или with_values=false накладывать нечего: 'value'
        // в ответ всё равно не попадёт, а сравнение с пустым $values дало бы
        // ложные unsaved_in_form на каждом поле.
        $unsaved = $withValues ? $unsavedValues : [];

        $pages = $query->get()->map(function (GlobalFieldPage $page) use ($values, $withValues, $unsaved) {
            $built = [
                'id' => $page->id,
                'name' => $page->name,
                'columns' => $page->columns ?? 1,
                'column_widths' => $page->column_widths,
                'sections' => $page->sections->map(fn ($section) => [
                    'id' => $section->id,
                    'name' => $section->name,
                    'column' => $section->column_index ?? 0,
                    'fields' => $section->fields->map(fn ($field) => $this->field($field, $values, $withValues, $unsaved))->values()->all(),
                ])->values()->all(),
            ];

            $built['layout'] = SettingsLayoutMap::render($built);

            return $built;
        })->values()->all();

        $orphans = GlobalField::whereNull('parent_id')
            ->whereNull('global_field_page_id')
            ->with('allChildren')
            ->orderBy('key')
            ->get()
            ->map(fn ($field) => $this->field($field, $values, $withValues, $unsaved))
            ->values()
            ->all();

        // Поле привязано к вкладке, но потеряло секцию (секцию удалили —
        // destroySection() полей не трогает). Список отдаём плоским, как и
        // orphan_fields: восстановление раскладки в apply_settings_layout всё
        // равно требует знать вкладку целиком, а не одно поле в отрыве от
        // остального дерева, поэтому вложенность внутрь pages[] здесь не
        // добавляет пользы — только 'page' для ориентира, к какой вкладке
        // поле относится.
        $detached = GlobalField::whereNull('parent_id')
            ->whereNotNull('global_field_page_id')
            ->whereNull('global_field_section_id')
            ->with(['fieldPage', 'allChildren'])
            ->orderBy('key')
            ->get()
            ->map(fn ($field) => $this->field($field, $values, $withValues, $unsaved) + ['page' => $field->fieldPage?->name])
            ->values()
            ->all();

        return array_filter([
            'pages' => $pages,
            'orphan_fields' => $orphans ?: null,
            'orphan_note' => $orphans
                ? 'Эти поля не привязаны ни к одной вкладке: в админке их не видно, но в $global они есть. Переселить их можно через apply_settings_layout, указав тот же ключ.'
                : null,
            'detached_fields' => $detached ?: null,
            'detached_note' => $detached
                ? 'Эти поля привязаны к вкладке (см. "page"), но не к секции: секцию удалили из админки, а поле осталось. В интерфейсе его не видно, но в $global оно есть и продолжает отдавать значение. Вернуть в раскладку можно через apply_settings_layout, указав тот же ключ и нужную секцию.'
                : null,
            // Пользователь мог вписать значение в форму /cms/settings и ещё не
            // сохранить её: без этой накладки агент читал бы поле из БД пустым
            // и предложил бы значение поверх того, что уже лежит в форме.
            'unsaved_note' => $unsaved
                ? 'У пользователя открыт экран значений настроек (/cms/settings) с несохранённой формой. Поля с unsaved_in_form=true показывают значение из этой формы, а не то, что сохранено в БД, — предлагая правку такому полю, учитывай именно его.'
                : null,
        ], fn ($value) => $value !== null);
    }

    /**
     * @param  array<string,mixed>  $values
     * @param  array<string,mixed>  $unsaved  несохранённые значения формы (только корневые ключи)
     * @return array<string,mixed>
     */
    private function field(GlobalField $field, array $values, bool $withValues, array $unsaved = []): array
    {
        $data = $field->data ?? [];
        $key = $field->key;

        // Форма считается «несохранённой» для поля только если в ней вообще
        // есть этот ключ и значение реально разошлось со значением из БД —
        // иначе на каждом поле висел бы unsaved_in_form=true, хотя пользователь
        // в форме ничего не менял (снапшот шлёт её целиком, а не diff).
        $hasUnsaved = $withValues
            && array_key_exists($key, $unsaved)
            && $this->normalize($unsaved[$key]) !== $this->normalize($values[$key] ?? null);

        $displayValue = $hasUnsaved ? $unsaved[$key] : ($values[$key] ?? null);

        return array_filter([
            'id' => $field->id,
            'key' => $key,
            'name' => $field->name,
            'type' => $field->type,
            'hint' => $data['hint'] ?? null,
            'required' => ($data['required'] ?? false) ? true : null,
            'options' => $data['options'] ?? null,
            'default_value' => $field->default_value,
            'value' => $withValues ? $this->shorten($displayValue) : null,
            'unsaved_in_form' => $hasUnsaved ? true : null,
            // Дети всегда без значений (withValues: false), независимо от родителя:
            // значения строк повторителя уже сжаты в value.first самого родителя
            // (см. shorten()), повторять их на каждом дочернем поле незачем.
            'children' => $field->allChildren->isNotEmpty()
                ? $field->allChildren->map(fn ($child) => $this->field($child, [], false))->values()->all()
                : null,
        ], fn ($value) => $value !== null);
    }

    /** Нормализация для сравнения «значение из БД» vs «значение из формы» без чувствительности к типам (int/string/JSON). */
    private function normalize(mixed $value): string
    {
        return is_array($value) ? json_encode($value) : (string) ($value ?? '');
    }

    private function shorten(mixed $value): mixed
    {
        if (is_string($value) && mb_strlen($value) > self::MAX_VALUE_CHARS) {
            return mb_substr($value, 0, self::MAX_VALUE_CHARS).'…';
        }

        if (is_array($value)) {
            return ['rows' => count($value), 'first' => array_map(
                fn ($v) => is_string($v) && mb_strlen($v) > 60 ? mb_substr($v, 0, 60).'…' : $v,
                (array) ($value[0] ?? [])
            )];
        }

        return $value;
    }
}
