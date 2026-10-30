<?php

namespace Templite\Cms\Services\Ai\Settings;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Templite\Cms\Models\BlockField;

/**
 * Что можно класть в глобальное поле: типы и требования к ключу.
 *
 * Api\GlobalSettingsController::storeField() валидирует ключ как обычную
 * строку — ни формата, ни уникальности. Между тем saveValues() строит карту
 * pluck('id', 'key'), а GlobalFieldsResolver собирает $result[$key]: два поля
 * с одним ключом дают молча непредсказуемый $global. Правила живут здесь.
 */
class SettingsKeyRules
{
    /** Типы глобальных полей — фолбэк config('cms.field_types') из Admin\GlobalSettingsController. */
    public const TYPES = [
        'text', 'textfield', 'number', 'img', 'file', 'editor', 'tiptap', 'html',
        'select', 'checkbox', 'radio', 'link', 'date', 'datetime',
        'array', 'color', 'page',
    ];

    /**
     * Имена, занятые переменными Blade и контекста рендеринга.
     *
     * Список общий с полями блока не случайно: глобальное поле и поле блока
     * попадают в одно и то же Blade-окружение, поэтому расходиться эти наборы
     * не должны. Ссылка вместо копии — список и так продублирован по
     * форм-реквестам, седьмая копия ушла бы из синхронизации первой.
     */
    public const RESERVED = BlockField::RESERVED_KEYS;

    /**
     * Уникальность ключа — по всему сайту, без скоупа по родителю.
     *
     * Скоуп «ключ + parent_id» разрешал бы двум повторителям иметь детей с
     * одинаковыми ключами ('title', 'url'), а saveValues() резолвит ключи
     * плоской картой pluck('id', 'key'): у одноимённых полей побеждает
     * последнее в карте, значения уходят в БД с чужим global_field_id, и
     * resolveRepeaterValues() их отбрасывает, потому что ищет значение среди
     * children своего поля. Инструмент при этом отвечает 'saved'. Запретить
     * одноимённые ключи дешевле и безопаснее, чем чинить saveValues(): он
     * общий с экраном настроек админки, где та же проблема существует
     * независимо от ассистента.
     *
     * @param  int|null  $ignoreId  существующее поле, которое не считается дублем самого себя
     * @return array<string,array<int,string>> ошибки валидатора; пустой массив — ключ годится
     */
    public function validateKey(string $key, ?int $ignoreId = null): array
    {
        $unique = Rule::unique('global_fields', 'key');

        $validator = Validator::make(['key' => $key], [
            'key' => [
                'required', 'string', 'max:64', 'regex:/^[a-z][a-z0-9_]*$/',
                Rule::notIn(self::RESERVED),
                $ignoreId ? $unique->ignore($ignoreId) : $unique,
            ],
        ], [
            'key.regex' => 'Ключ пишется латиницей в snake_case и начинается с буквы: site_phone.',
            'key.unique' => 'Поле с таким ключом уже есть. Ключ уникален по всему сайту, включая вложенные поля повторителей: при записи значений ключи резолвятся плоской картой ключ → id, поэтому одинаковых имён не бывает даже у полей разных повторителей — возьми уточнённый ключ вроде socials_url.',
            'key.not_in' => 'Ключ занят служебной переменной Blade. Нельзя использовать: '.implode(', ', self::RESERVED).'.',
        ]);

        return $validator->fails() ? $validator->errors()->toArray() : [];
    }

    /** @return array<string,array<int,string>> */
    public function validateType(string $type): array
    {
        return in_array($type, self::TYPES, true)
            ? []
            : ['type' => ['Тип «'.$type.'» не поддерживается. Доступные: '.implode(', ', self::TYPES).'.']];
    }
}
