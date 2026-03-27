<?php

namespace Templite\Cms\Support;

/**
 * Приведение значений полей к правильным PHP-типам.
 *
 * Значения полей в БД хранятся как строки (GlobalFieldValue.value — text,
 * BlockField.default_value — string). Этот класс приводит их к типам,
 * которые ожидают Vue-компоненты и Blade-шаблоны.
 *
 * Используется в:
 *   - GlobalFieldsMiddleware (публичный сайт)
 *   - Admin\GlobalSettingsController (админка, Inertia props)
 *   - BlockDataResolver (default_value блочных полей)
 */
final class FieldValueCaster
{
    /**
     * Привести строковое значение поля к нужному PHP-типу.
     *
     * @param  mixed   $raw       Значение из БД (обычно string)
     * @param  string  $fieldType Тип поля (text, link, img, checkbox, etc.)
     * @return mixed
     */
    public static function cast(mixed $raw, string $fieldType): mixed
    {
        if ($raw === null) {
            return $raw;
        }

        // Пустая строка — для checkbox это false, для остальных — пустое значение
        if ($raw === '') {
            return $fieldType === 'checkbox' ? false : $raw;
        }

        return match ($fieldType) {
            // link: JSON-объект {url, target} → массив
            'link' => self::jsonDecode($raw),

            // select: может быть JSON-массив ["a","b"] (multiple) или скаляр (single)
            'select' => self::jsonDecodeIfJson($raw),

            // img, file: file ID → int
            'img', 'file' => is_numeric($raw) ? (int) $raw : $raw,

            // checkbox: "1"/"0"/"true"/"false" → bool (Vue CheckboxField использует ===)
            'checkbox' => filter_var($raw, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? $raw,

            // number: "42" → int, "3.14" → float
            'number' => is_numeric($raw)
                ? (str_contains((string) $raw, '.') ? (float) $raw : (int) $raw)
                : $raw,

            // text, textfield, editor, tiptap, html, color, date, datetime, radio — строка как есть
            default => $raw,
        };
    }

    /**
     * JSON-декодирование (всегда пытаемся декодировать строку).
     */
    private static function jsonDecode(mixed $raw): mixed
    {
        if (!is_string($raw)) {
            return $raw;
        }

        $decoded = json_decode($raw, true);

        return (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) ? $decoded : $raw;
    }

    /**
     * JSON-декодирование только если строка начинается с [ или { .
     */
    private static function jsonDecodeIfJson(mixed $raw): mixed
    {
        if (!is_string($raw)) {
            return $raw;
        }

        $trimmed = ltrim($raw);
        if ($trimmed === '' || ($trimmed[0] !== '[' && $trimmed[0] !== '{')) {
            return $raw;
        }

        $decoded = json_decode($raw, true);

        return (json_last_error() === JSON_ERROR_NONE) ? $decoded : $raw;
    }
}
