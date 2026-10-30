<?php

namespace Templite\Cms\Services\Ai\Settings;

use Templite\Cms\Models\File;
use Templite\Cms\Models\GlobalField;

/**
 * Сверка присланных значений с типами полей.
 *
 * saveValues() принимает что угодно и пишет как есть: подсунутое в поле img
 * название файла вместо id обнаружится только пустой картинкой на странице.
 */
class GlobalValueWriter
{
    /**
     * @param  array<string,mixed>  $values
     * @return array<string,array<int,string>> ошибки по ключам; пусто — можно писать
     */
    public function validate(array $values): array
    {
        $fields = GlobalField::whereIn('key', array_keys($values))->whereNull('parent_id')->get()->keyBy('key');
        $errors = [];

        foreach ($values as $key => $value) {
            $field = $fields->get($key);

            if (! $field) {
                $errors[$key][] = 'Поля с таким ключом нет. Заведи его через apply_settings_layout или проверь список в list_global_fields.';

                continue;
            }

            if ($field->type === 'array') {
                foreach ($this->validateRepeater($field, $value) as $message) {
                    $errors[$key][] = $message;
                }

                continue;
            }

            foreach ($this->validateScalar($field, $value) as $message) {
                $errors[$key][] = $message;
            }
        }

        return $errors;
    }

    /**
     * Значение по умолчанию файлового поля — по тем же правилам, что и значение.
     *
     * GlobalField::getValue() отдаёт default_value, пока значения нет, а
     * FieldValueCaster::cast() превращает числовую строку в id файла. Без этой
     * проверки apply_settings_layout с default_value = id карантинной картинки
     * ставил бы её логотипом сайта в обход подтверждения: validate() до
     * default_value не добирается, там речь только о values.
     *
     * @return array<int,string>
     */
    public function validateDefault(string $type, mixed $value): array
    {
        if (! in_array($type, ['img', 'file'], true) || $value === null || $value === '') {
            return [];
        }

        return $this->validateFile($value);
    }

    /** @return array<int,string> */
    private function validateRepeater(GlobalField $field, mixed $value): array
    {
        if (! is_array($value) || ! array_is_list($value)) {
            return ['Повторитель принимает список строк: [{"title": "…", "url": "…"}].'];
        }

        $children = $field->children->keyBy('key');
        $messages = [];

        foreach ($value as $index => $row) {
            if (! is_array($row)) {
                $messages[] = "Строка {$index} должна быть объектом из полей повторителя.";

                continue;
            }

            foreach ($row as $childKey => $childValue) {
                $child = $children->get($childKey);

                if (! $child) {
                    $messages[] = "В строке {$index} нет поля «{$childKey}» — у повторителя есть: ".$children->keys()->implode(', ').'.';

                    continue;
                }

                if (is_array($childValue) && array_is_list($childValue)) {
                    $messages[] = "Поле «{$childKey}» — повторитель внутри повторителя. Второй уровень вложенности не сохраняется и не рендерится.";

                    continue;
                }

                foreach ($this->validateScalar($child, $childValue) as $message) {
                    $messages[] = "Строка {$index}, поле «{$childKey}»: {$message}";
                }
            }
        }

        return $messages;
    }

    /** @return array<int,string> */
    private function validateScalar(GlobalField $field, mixed $value): array
    {
        if ($value === null || $value === '') {
            return [];
        }

        $options = $field->data['options'] ?? null;

        return match ($field->type) {
            'img', 'file' => $this->validateFile($value),
            'number' => is_numeric($value) ? [] : ['Ожидается число.'],
            'link' => is_array($value) && isset($value['url'])
                ? []
                : ['Ожидается объект {"url": "https://…", "target": "_blank"}.'],
            'select', 'radio' => ! $options || in_array($value, (array) $options, true)
                ? []
                : ['Значение вне списка вариантов поля: '.implode(', ', (array) $options).'.'],
            default => [],
        };
    }

    /**
     * Файл из медиатеки — и только подтверждённый.
     *
     * Сгенерированные ассистентом картинки лежат с ai_pending до тех пор,
     * пока пользователь не подтвердит их в чате. UpdateBlockTool такие в блок
     * не пускает; без той же проверки здесь агент поставил бы неподтверждённую
     * картинку логотипом сайта в обход подтверждения.
     *
     * @return array<int,string>
     */
    private function validateFile(mixed $value): array
    {
        if (! is_numeric($value)) {
            return ['Ожидается id файла из медиатеки (list_media), получено: '.json_encode($value, JSON_UNESCAPED_UNICODE).'.'];
        }

        $file = File::find((int) $value);

        if (! $file) {
            return ['Файла с id '.(int) $value.' в медиатеке нет — проверь список через list_media.'];
        }

        return $file->ai_pending
            ? ['Изображение ещё не подтверждено пользователем. Дождись подтверждения в чате — подставлять его нельзя.']
            : [];
    }
}
