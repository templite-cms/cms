<?php

namespace Templite\Cms\Services\ImportExport;

use Illuminate\Support\Collection;

/**
 * Утилита для сканирования данных блока и извлечения file ID из полей типа img/file.
 *
 * Используется при экспорте/импорте для автоматического определения
 * зависимых медиафайлов и ремаппинга ID.
 */
class MediaFieldScanner
{
    /**
     * Заменить числовые ID файлов на пути (для экспорта).
     *
     * Проходит по полям типа img/file и подставляет путь файла
     * из переданной коллекции $fileMap (keyed by ID).
     * Для array-повторителей рекурсивно обрабатывает дочерние строки.
     * Остальные поля остаются без изменений.
     *
     * @param Collection $fields Коллекция BlockField (key, type, children)
     * @param array $data Данные блока (ключ => значение)
     * @param Collection $fileMap Коллекция File, индексированная по ID
     * @return array Данные блока с путями вместо ID
     */
    public static function replaceIdsWithPaths(Collection $fields, array $data, Collection $fileMap): array
    {
        return static::transformData($fields, $data, function (mixed $value) use ($fileMap): mixed {
            if (is_numeric($value)) {
                return $fileMap[(int) $value]->path ?? null;
            }

            return $value;
        });
    }

    /**
     * Заменить строковые пути файлов на ID (для импорта).
     *
     * Для полей типа img/file: если значение -- строка, вызывает
     * $ctx->resolveId('file', $path) для получения нового ID.
     * Числовые значения (старый формат экспорта) остаются без изменений
     * для обратной совместимости.
     *
     * @param Collection $fields Коллекция BlockField (key, type, children)
     * @param array $data Данные блока (ключ => значение)
     * @param ImportContext $ctx Контекст импорта с маппингом
     * @return array Данные блока с ID вместо путей
     */
    public static function replacePathsWithIds(Collection $fields, array $data, ImportContext $ctx): array
    {
        return static::transformData($fields, $data, function (mixed $value) use ($ctx): mixed {
            if (is_numeric($value)) {
                return $value;
            }

            if (is_string($value) && $value !== '') {
                return $ctx->resolveId('file', $value);
            }

            return $value;
        });
    }

    /**
     * Извлечь все уникальные file ID из данных блока по определениям полей.
     *
     * @param Collection $fields Коллекция BlockField (key, type, children)
     * @param array $data Данные блока (ключ => значение)
     * @return int[] Дедуплицированный массив целочисленных file ID
     */
    public static function extractFileIds(Collection $fields, array $data): array
    {
        $ids = [];

        static::walkFields($fields, $data, function (string $key, mixed $value) use (&$ids) {
            if (is_numeric($value)) {
                $ids[] = (int) $value;
            }
        });

        return array_values(array_unique($ids));
    }

    // ---------------------------------------------------------------
    // Global Fields — exported format: [{key, type, values: [{value, translations}], children}]
    // ---------------------------------------------------------------

    /**
     * Извлечь все file ID из экспортированной структуры глобальных полей.
     *
     * Формат входных данных: массив полей, каждое поле имеет key, type,
     * values (с value и translations), и рекурсивные children.
     * Для полей типа img/file: если value числовое -- это file ID.
     *
     * @param array $fieldsData Экспортированная структура полей
     * @return int[] Дедуплицированный массив целочисленных file ID
     */
    public static function extractFileIdsFromExportedGlobalFields(array $fieldsData): array
    {
        $ids = [];
        static::walkGlobalFields($fieldsData, function (mixed $value) use (&$ids) {
            if (is_numeric($value)) {
                $ids[] = (int) $value;
            }
        });

        return array_values(array_unique($ids));
    }

    /**
     * Заменить числовые file ID на пути в экспортированной структуре глобальных полей.
     *
     * @param array $fieldsData Экспортированная структура полей
     * @param Collection $fileMap Коллекция File, индексированная по ID
     * @return array Структура с путями вместо ID
     */
    public static function replaceGlobalFieldIdsWithPaths(array $fieldsData, Collection $fileMap): array
    {
        return static::transformGlobalFields($fieldsData, function (mixed $value) use ($fileMap): mixed {
            if (is_numeric($value)) {
                return $fileMap[(int) $value]->path ?? null;
            }
            return $value;
        });
    }

    /**
     * Заменить строковые пути на file ID в экспортированной структуре глобальных полей.
     *
     * Числовые значения (старый формат) остаются без изменений для обратной совместимости.
     *
     * @param array $fieldsData Экспортированная структура полей
     * @param ImportContext $ctx Контекст импорта
     * @return array Структура с ID вместо путей
     */
    public static function replaceGlobalFieldPathsWithIds(array $fieldsData, ImportContext $ctx): array
    {
        return static::transformGlobalFields($fieldsData, function (mixed $value) use ($ctx): mixed {
            if (is_numeric($value)) {
                return $value;
            }
            if (is_string($value) && $value !== '') {
                return $ctx->resolveId('file', $value);
            }
            return $value;
        });
    }

    /**
     * Обойти все img/file поля в структуре глобальных полей и вызвать callback.
     *
     * @param array $fieldsData Структура полей
     * @param callable $callback Функция (mixed $value): void
     */
    protected static function walkGlobalFields(array $fieldsData, callable $callback): void
    {
        foreach ($fieldsData as $field) {
            $type = $field['type'] ?? '';
            if ($type === 'img' || $type === 'file') {
                // Scan main values
                foreach ($field['values'] ?? [] as $val) {
                    $v = $val['value'] ?? null;
                    if ($v !== null && $v !== '') {
                        $callback($v);
                    }
                    // Scan translations
                    foreach ($val['translations'] ?? [] as $t) {
                        $tv = $t['value'] ?? null;
                        if ($tv !== null && $tv !== '') {
                            $callback($tv);
                        }
                    }
                }
            }
            // Recurse into children
            if (!empty($field['children'])) {
                static::walkGlobalFields($field['children'], $callback);
            }
        }
    }

    /**
     * Трансформировать значения img/file полей в структуре глобальных полей.
     *
     * @param array $fieldsData Структура полей
     * @param callable $transform Функция (mixed $value): mixed
     * @return array Трансформированная структура
     */
    protected static function transformGlobalFields(array $fieldsData, callable $transform): array
    {
        return array_map(function (array $field) use ($transform): array {
            $type = $field['type'] ?? '';
            if ($type === 'img' || $type === 'file') {
                $field['values'] = array_map(function (array $val) use ($transform): array {
                    $v = $val['value'] ?? null;
                    if ($v !== null && $v !== '') {
                        $val['value'] = $transform($v);
                    }
                    // Transform translations
                    if (!empty($val['translations'])) {
                        $val['translations'] = array_map(function (array $t) use ($transform): array {
                            $tv = $t['value'] ?? null;
                            if ($tv !== null && $tv !== '') {
                                $t['value'] = $transform($tv);
                            }
                            return $t;
                        }, $val['translations']);
                    }
                    return $val;
                }, $field['values'] ?? []);
            }
            // Recurse into children
            if (!empty($field['children'])) {
                $field['children'] = static::transformGlobalFields($field['children'], $transform);
            }
            return $field;
        }, $fieldsData);
    }

    // ---------------------------------------------------------------
    // Block data — field definitions + flat data array
    // ---------------------------------------------------------------

    /**
     * Рекурсивно обойти все поля типа img/file в данных блока и вызвать callback для каждого.
     *
     * Для полей типа array (repeater) рекурсивно обходит дочерние поля
     * для каждой строки данных.
     *
     * @param Collection $fields Коллекция BlockField
     * @param array $data Данные блока
     * @param callable $callback Функция (string $key, mixed $value): void
     */
    protected static function walkFields(Collection $fields, array $data, callable $callback): void
    {
        foreach ($fields as $field) {
            $key = $field->key;
            $value = $data[$key] ?? null;

            if ($value === null || $value === '') {
                continue;
            }

            if ($field->type === 'img' || $field->type === 'file') {
                $callback($key, $value);
            } elseif ($field->type === 'array' && is_array($value)) {
                $childFields = $field->children ?? collect();
                if ($childFields instanceof Collection && $childFields->isNotEmpty()) {
                    foreach ($value as $row) {
                        if (is_array($row)) {
                            static::walkFields($childFields, $row, $callback);
                        }
                    }
                }
            }
        }
    }

    /**
     * Универсальный трансформер данных блока по определениям полей.
     *
     * Проходит по всем полям: для img/file применяет $transform к значению,
     * для array (repeater) рекурсивно обрабатывает каждую строку.
     * Остальные поля копируются без изменений.
     *
     * @param Collection $fields Коллекция BlockField (key, type, children)
     * @param array $data Данные блока
     * @param callable $transform Функция (mixed $value): mixed для img/file полей
     * @return array Трансформированные данные
     */
    protected static function transformData(Collection $fields, array $data, callable $transform): array
    {
        $result = $data;

        foreach ($fields as $field) {
            $key = $field->key;
            $value = $data[$key] ?? null;

            if ($value === null || $value === '') {
                continue;
            }

            if ($field->type === 'img' || $field->type === 'file') {
                $result[$key] = $transform($value);
            } elseif ($field->type === 'array' && is_array($value)) {
                $childFields = $field->children ?? collect();
                if ($childFields instanceof Collection && $childFields->isNotEmpty()) {
                    $result[$key] = array_values(array_map(
                        fn(array $row) => static::transformData($childFields, $row, $transform),
                        array_filter($value, 'is_array')
                    ));
                }
            }
        }

        return $result;
    }
}
