<?php

namespace Templite\Cms\Services\Ai\Settings;

/**
 * Слияние data-атрибутов поля (hint/required/options) при точечной правке.
 *
 * Общая реализация для ApplySettingsLayoutTool::upsertField() и
 * UpdateSettingsFieldTool: до этой правки apply_settings_layout ключ null
 * удалял из data, а update_settings_field писал буквально null/false в JSON —
 * одинаковое намерение агента («убрать подсказку») давало разное содержимое
 * колонки в зависимости от того, каким инструментом он воспользовался.
 * Общий код исключает повторное расхождение.
 */
class SettingsFieldDataMerger
{
    /**
     * Ключ, отсутствующий в $args, не трогает старое значение в $data. Ключ,
     * который есть и равен null (hint, options) или falsy (required), —
     * очищает его из $data, а не записывает буквально.
     *
     * @param  array<string,mixed>  $data  текущий data поля
     * @param  array<string,mixed>  $args  аргументы вызова инструмента
     * @return array<string,mixed>
     */
    public static function merge(array $data, array $args): array
    {
        if (array_key_exists('hint', $args)) {
            if ($args['hint'] === null) {
                unset($data['hint']);
            } else {
                $data['hint'] = $args['hint'];
            }
        }

        if (array_key_exists('required', $args)) {
            if ($args['required']) {
                $data['required'] = true;
            } else {
                unset($data['required']);
            }
        }

        if (array_key_exists('options', $args)) {
            if ($args['options'] === null) {
                unset($data['options']);
            } else {
                $data['options'] = $args['options'];
            }
        }

        return $data;
    }
}
