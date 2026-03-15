<?php

namespace Templite\Cms\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\MessageBag;
use Templite\Cms\Models\BlockField;
use Templite\Cms\Models\File;

/**
 * BF-015: BlockDataValidator -- валидация данных page_blocks.data по схеме полей блока.
 *
 * Используется при сохранении данных блока на странице (PUT /api/cms/page-blocks/{id}).
 * Валидирует данные на основе определений block_fields:
 * - Поля с data.required = true обязательны
 * - Поля типа number проверяются на min/max
 * - Поля типа select/radio проверяются на допустимые options
 * - Поля типа img/file проверяют file_id на существование
 * - Поля типа array рекурсивно валидируют вложенные данные
 */
class BlockDataValidator
{
    /**
     * Валидировать данные блока по схеме полей.
     *
     * @param Collection<BlockField> $fields Поля блока (top-level или children)
     * @param array $data Данные для валидации
     * @return MessageBag Ошибки валидации (пустой если валидно)
     */
    public function validate(Collection $fields, array $data): MessageBag
    {
        $errors = new MessageBag();

        foreach ($fields as $field) {
            $key = $field->key;
            $value = $data[$key] ?? null;
            $fieldErrors = $this->validateField($field, $value, $key);

            foreach ($fieldErrors as $errorKey => $errorMessages) {
                foreach ($errorMessages as $message) {
                    $errors->add($errorKey, $message);
                }
            }
        }

        return $errors;
    }

    /**
     * Валидировать одно поле.
     */
    protected function validateField(BlockField $field, mixed $value, string $path): array
    {
        $errors = [];
        $fieldData = $field->data ?? [];
        $required = $fieldData['required'] ?? false;

        // Проверка обязательности
        if ($required && ($value === null || $value === '' || $value === [])) {
            $errors[$path][] = "Поле \"{$field->name}\" обязательно для заполнения.";
            return $errors;
        }

        // Если значение пустое и не обязательное -- пропускаем
        if ($value === null || $value === '') {
            return $errors;
        }

        // Валидация по типу
        switch ($field->type) {
            case 'text':
            case 'textfield':
            case 'editor':
            case 'html':
            case 'color':
                $errors = array_merge($errors, $this->validateStringField($field, $value, $path, $fieldData));
                break;

            case 'number':
                $errors = array_merge($errors, $this->validateNumberField($field, $value, $path, $fieldData));
                break;

            case 'img':
                $errors = array_merge($errors, $this->validateImageField($field, $value, $path, $fieldData));
                break;

            case 'file':
                $errors = array_merge($errors, $this->validateFileField($field, $value, $path));
                break;

            case 'select':
            case 'radio':
                $errors = array_merge($errors, $this->validateSelectField($field, $value, $path, $fieldData));
                break;

            case 'checkbox':
                if (!is_bool($value) && !in_array($value, [0, 1, '0', '1', true, false], true)) {
                    $errors[$path][] = "Поле \"{$field->name}\" должно быть булевым значением.";
                }
                break;

            case 'link':
                $errors = array_merge($errors, $this->validateLinkField($field, $value, $path));
                break;

            case 'date':
                if (is_string($value) && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
                    $errors[$path][] = "Поле \"{$field->name}\" должно быть в формате YYYY-MM-DD.";
                }
                break;

            case 'datetime':
                if (is_string($value) && !preg_match('/^\d{4}-\d{2}-\d{2}[\sT]\d{2}:\d{2}/', $value)) {
                    $errors[$path][] = "Поле \"{$field->name}\" должно быть в формате YYYY-MM-DD HH:MM.";
                }
                break;

            case 'array':
                $errors = array_merge($errors, $this->validateArrayField($field, $value, $path));
                break;

            case 'category':
            case 'product':
            case 'product_option':
                if (!is_numeric($value)) {
                    $errors[$path][] = "Поле \"{$field->name}\" должно содержать числовой ID.";
                }
                break;
        }

        return $errors;
    }

    /**
     * Валидация строковых полей.
     */
    protected function validateStringField(BlockField $field, mixed $value, string $path, array $fieldData): array
    {
        $errors = [];

        if (!is_string($value)) {
            $errors[$path][] = "Поле \"{$field->name}\" должно быть строкой.";
            return $errors;
        }

        if (isset($fieldData['max_length']) && mb_strlen($value) > $fieldData['max_length']) {
            $errors[$path][] = "Поле \"{$field->name}\" не должно превышать {$fieldData['max_length']} символов.";
        }

        if (isset($fieldData['min_length']) && mb_strlen($value) < $fieldData['min_length']) {
            $errors[$path][] = "Поле \"{$field->name}\" должно содержать минимум {$fieldData['min_length']} символов.";
        }

        // Валидация цвета
        if ($field->type === 'color' && !preg_match('/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6}|[0-9a-fA-F]{8})$/', $value)) {
            $errors[$path][] = "Поле \"{$field->name}\" должно быть валидным HEX-цветом (например, #FF0000).";
        }

        return $errors;
    }

    /**
     * Валидация числовых полей с min/max.
     */
    protected function validateNumberField(BlockField $field, mixed $value, string $path, array $fieldData): array
    {
        $errors = [];

        if (!is_numeric($value)) {
            $errors[$path][] = "Поле \"{$field->name}\" должно быть числом.";
            return $errors;
        }

        $numericValue = (float) $value;

        if (isset($fieldData['min']) && $numericValue < $fieldData['min']) {
            $errors[$path][] = "Поле \"{$field->name}\" не может быть меньше {$fieldData['min']}.";
        }

        if (isset($fieldData['max']) && $numericValue > $fieldData['max']) {
            $errors[$path][] = "Поле \"{$field->name}\" не может быть больше {$fieldData['max']}.";
        }

        if (isset($fieldData['step']) && $fieldData['step'] > 0) {
            $remainder = fmod($numericValue, $fieldData['step']);
            if (abs($remainder) > 0.0001) {
                $errors[$path][] = "Поле \"{$field->name}\" должно быть кратно {$fieldData['step']}.";
            }
        }

        return $errors;
    }

    /**
     * Валидация поля изображения (проверка существования файла).
     */
    protected function validateImageField(BlockField $field, mixed $value, string $path, array $fieldData): array
    {
        $errors = [];

        if (!is_numeric($value)) {
            $errors[$path][] = "Поле \"{$field->name}\" должно содержать ID файла.";
            return $errors;
        }

        // Проверка существования файла в БД
        if (!File::where('id', (int) $value)->exists()) {
            $errors[$path][] = "Файл для поля \"{$field->name}\" не найден (ID: {$value}).";
        }

        return $errors;
    }

    /**
     * Валидация файлового поля.
     */
    protected function validateFileField(BlockField $field, mixed $value, string $path): array
    {
        $errors = [];

        if (!is_numeric($value)) {
            $errors[$path][] = "Поле \"{$field->name}\" должно содержать ID файла.";
            return $errors;
        }

        // Проверка существования файла в БД
        if (!File::where('id', (int) $value)->exists()) {
            $errors[$path][] = "Файл для поля \"{$field->name}\" не найден (ID: {$value}).";
        }

        return $errors;
    }

    /**
     * Валидация select/radio полей с проверкой допустимых options.
     */
    protected function validateSelectField(BlockField $field, mixed $value, string $path, array $fieldData): array
    {
        $errors = [];

        if (isset($fieldData['options']) && is_array($fieldData['options'])) {
            $validValues = array_column($fieldData['options'], 'value');
            if (!empty($validValues) && !in_array($value, $validValues, false)) {
                $errors[$path][] = "Недопустимое значение для поля \"{$field->name}\".";
            }
        }

        return $errors;
    }

    /**
     * Валидация поля ссылки.
     */
    protected function validateLinkField(BlockField $field, mixed $value, string $path): array
    {
        $errors = [];

        if (!is_array($value)) {
            $errors[$path][] = "Поле \"{$field->name}\" должно быть объектом ссылки.";
            return $errors;
        }

        // Ссылка может содержать url, text, target, page_id
        if (isset($value['url']) && !is_string($value['url'])) {
            $errors["{$path}.url"][] = "URL ссылки должен быть строкой.";
        }

        if (isset($value['text']) && !is_string($value['text'])) {
            $errors["{$path}.text"][] = "Текст ссылки должен быть строкой.";
        }

        if (isset($value['target']) && !in_array($value['target'], ['_self', '_blank', '_parent', '_top'])) {
            $errors["{$path}.target"][] = "Недопустимое значение target для ссылки.";
        }

        return $errors;
    }

    /**
     * Валидация поля-повторителя (array) с рекурсивной валидацией вложенных полей.
     */
    protected function validateArrayField(BlockField $field, mixed $value, string $path): array
    {
        $errors = [];

        if (!is_array($value)) {
            $errors[$path][] = "Поле \"{$field->name}\" должно быть массивом.";
            return $errors;
        }

        $fieldData = $field->data ?? [];

        // Проверка минимального количества элементов
        if (isset($fieldData['min_items']) && count($value) < $fieldData['min_items']) {
            $errors[$path][] = "Поле \"{$field->name}\" должно содержать минимум {$fieldData['min_items']} элементов.";
        }

        // Проверка максимального количества элементов
        if (isset($fieldData['max_items']) && count($value) > $fieldData['max_items']) {
            $errors[$path][] = "Поле \"{$field->name}\" не должно содержать более {$fieldData['max_items']} элементов.";
        }

        // Рекурсивная валидация вложенных полей
        $childFields = $field->relationLoaded('children')
            ? $field->children
            : $field->children()->orderBy('order')->get();

        if ($childFields->isNotEmpty()) {
            foreach ($value as $index => $item) {
                if (!is_array($item)) {
                    $errors["{$path}.{$index}"][] = "Элемент повторителя должен быть объектом.";
                    continue;
                }
                $itemErrors = $this->validate($childFields, $item);
                foreach ($itemErrors->getMessages() as $itemKey => $messages) {
                    $errors["{$path}.{$index}.{$itemKey}"] = $messages;
                }
            }
        }

        return $errors;
    }
}
