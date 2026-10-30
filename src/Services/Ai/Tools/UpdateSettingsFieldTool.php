<?php

namespace Templite\Cms\Services\Ai\Tools;

use Templite\Cms\Http\Controllers\Api\GlobalSettingsController;
use Templite\Cms\Models\GlobalField;
use Templite\Cms\Models\GlobalFieldSection;
use Templite\Cms\Models\GlobalFieldValue;
use Templite\Cms\Services\Ai\EditorContext;
use Templite\Cms\Services\Ai\Settings\GlobalValueWriter;
use Templite\Cms\Services\Ai\Settings\SettingsFieldDataMerger;
use Templite\Cms\Services\Ai\Settings\SettingsKeyRules;
use Templite\Cms\Services\CacheManager;

/**
 * Точечная правка одного глобального поля: подпись, тип, подсказка, варианты,
 * значение по умолчанию, перенос в другую секцию.
 *
 * Ключ неизменяем — как у полей блока: сменить его значит осиротить все
 * $global['старый_ключ'] в вёрстке, причём молча.
 */
class UpdateSettingsFieldTool implements Tool
{
    use ChecksSettingsPermission;

    public function __construct(
        private readonly SettingsKeyRules $rules,
        private readonly CacheManager $cache,
        private readonly GlobalValueWriter $writer,
        // Пишет в аудит-лог тем же методом, что и ручная правка через API —
        // см. комментарий у ApplySettingsLayoutTool::$auditLog.
        private readonly GlobalSettingsController $auditLog,
    ) {
    }

    public function name(): string
    {
        return 'update_settings_field';
    }

    public function definition(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'field_id' => ['type' => 'integer', 'description' => 'ID поля из list_global_fields'],
                'name' => ['type' => 'string'],
                'type' => ['type' => 'string', 'enum' => SettingsKeyRules::TYPES],
                'hint' => ['type' => 'string'],
                'required' => ['type' => 'boolean'],
                'options' => ['type' => 'array', 'items' => ['type' => 'string']],
                'default_value' => ['type' => 'string'],
                'section_id' => ['type' => 'integer', 'description' => 'Перенести поле в другую секцию'],
                'order' => ['type' => 'integer'],
            ],
            'required' => ['field_id'],
        ];
    }

    public function execute(array $args, ?EditorContext $ctx): array
    {
        if ($deny = $this->denyIfNoSettingsPermission('settings.edit')) {
            return $deny;
        }

        $field = GlobalField::find((int) ($args['field_id'] ?? 0));

        if (! $field) {
            return ['error' => 'field_not_found', 'field_id' => $args['field_id'] ?? null];
        }

        if (isset($args['type']) && ($errors = $this->rules->validateType((string) $args['type']))) {
            return ['error' => 'validation_failed', 'details' => $errors];
        }

        $finalType = (string) ($args['type'] ?? $field->type);

        // У повторителя (array) значения лежат строками-контейнерами с
        // дочерними записями (см. GlobalFieldsResolver::resolveRepeaterValues),
        // у скаляра — одной строкой в той же таблице. Перескочить между этими
        // двумя формами, не тронув уже записанные значения, значит оставить
        // их в БД нечитаемыми: resolve() для нового типа просто не найдёт то,
        // что искал старый. Запрещаем смену, если значения уже есть, — а не
        // предупреждаем и пускаем: предупреждение прочитал бы только тот, кто
        // читает ответ инструмента, а молчаливая порча данных бьёт и того, кто
        // прочитал бы страницу сайта позже.
        if ($finalType !== $field->type && (($finalType === 'array') !== ($field->type === 'array'))) {
            $hasValues = GlobalFieldValue::where('global_field_id', $field->id)->exists();

            if ($hasValues) {
                return [
                    'error' => 'type_change_blocked',
                    'message' => 'У поля «'.$field->key.'» уже есть записанные значения в формате типа «'.$field->type.'». Смена на «'.$finalType.'» их не переносит: повторитель хранит значения иначе, чем скаляр, — после смены типа они станут нечитаемыми. Удали значения (set_global_values с пустым значением для скаляра или delete_settings_node для поля целиком) и повтори.',
                ];
            }
        }

        // У select/radio нет смысла без вариантов: агент сможет завести поле,
        // но человеку в форме будет нечего выбирать.
        if (in_array($finalType, ['select', 'radio'], true)) {
            $finalOptions = array_key_exists('options', $args) ? $args['options'] : ($field->data['options'] ?? null);

            if (! $finalOptions) {
                return [
                    'error' => 'validation_failed',
                    'details' => ['options' => ['Полю типа «'.$finalType.'» нужны варианты — передай options.']],
                ];
            }
        }

        // Значение по умолчанию у поля img/file — тот же id файла, что и
        // обычное значение, поэтому и карантин ai_pending на нём тот же.
        // Сверяем итоговую пару тип + default: сменить тип на файловый, не
        // трогая уже записанный default, — тот же обход, только в два шага.
        if (array_key_exists('default_value', $args) || array_key_exists('type', $args)) {
            $default = array_key_exists('default_value', $args) ? $args['default_value'] : $field->default_value;

            if ($errors = $this->writer->validateDefault($finalType, $default)) {
                return ['error' => 'validation_failed', 'details' => ['default_value' => $errors]];
            }
        }

        $attributes = array_filter([
            'name' => $args['name'] ?? null,
            'type' => $args['type'] ?? null,
            'default_value' => $args['default_value'] ?? null,
            'order' => $args['order'] ?? null,
        ], fn ($value) => $value !== null);

        if (array_key_exists('section_id', $args)) {
            $section = GlobalFieldSection::find((int) $args['section_id']);

            if (! $section) {
                return ['error' => 'section_not_found', 'section_id' => $args['section_id']];
            }

            $attributes['global_field_section_id'] = $section->id;
            $attributes['global_field_page_id'] = $section->global_field_page_id;
        }

        // Общая с ApplySettingsLayoutTool::upsertField() семантика: ключ,
        // которого нет в $args, не трогается; ключ со значением null/false
        // очищается из data, а не записывается туда буквально.
        $attributes['data'] = SettingsFieldDataMerger::merge($field->data ?? [], $args) ?: null;

        $field->update($attributes);
        $this->cache->invalidateGlobalFields();

        // Тот же журнал и тот же формат записи, что у ручной правки через
        // Api\GlobalSettingsController::updateField().
        $this->auditLog->logAction('update', 'global_settings', $field->id, ['name' => $field->name, 'key' => $field->key, 'entity' => 'field']);

        $result = [
            'status' => 'updated',
            'field_id' => $field->id,
            'key' => $field->key,
            'note' => 'Ключ поля не меняется: вёрстка обращается к нему по имени.',
        ];

        // Правка ушла прямо в базу — оба экрана настроек после неё показывают
        // устаревшее. Сигнал один на оба, перечитывает каждый своё.
        if ($ctx && $ctx->isSettingsEditorOpen()) {
            $result['editor_op'] = ['op' => 'refresh_settings', 'payload' => ['field_id' => $field->id]];
        }

        return $result;
    }
}
