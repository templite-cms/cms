<?php

use Templite\Cms\Models\File;

if (!function_exists('cms_file')) {
    /**
     * Резолв файла в модель File.
     *
     * Принимает id (int/числовую строку) или уже готовую модель File.
     * Удобно для глобальных img/file-полей, где хранится id, а не модель
     * (см. $global[...] — в отличие от $fields[...] в блоках).
     *
     * @return File|null null, если файл не найден или значение некорректно
     */
    function cms_file(mixed $value): ?File
    {
        if ($value instanceof File) {
            return $value;
        }

        if (!is_numeric($value)) {
            return null;
        }

        return File::find((int) $value);
    }
}

if (!function_exists('cms_file_url')) {
    /**
     * URL файла по id или модели File.
     *
     * Для вывода в src/href, когда нельзя использовать <x-cms::image>
     * (например inline-SVG, фон в style). Пустая строка, если файла нет.
     *
     * @param string|null $size   ключ размера (thumb, card, ...); null — оригинал
     * @param string      $format original|webp|avif
     */
    function cms_file_url(mixed $value, ?string $size = null, string $format = 'original'): string
    {
        $file = cms_file($value);

        return $file ? $file->url($size, $format) : '';
    }
}
