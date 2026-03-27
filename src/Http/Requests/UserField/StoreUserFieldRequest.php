<?php

namespace Templite\Cms\Http\Requests\UserField;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Templite\Cms\Models\UserField;

class StoreUserFieldRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $typeId = (int) $this->route('typeId');
        $parentId = $this->input('parent_id');

        $rules = [
            'name' => ['required', 'string', 'max:255'],
            'key' => [
                'required',
                'string',
                'max:64',
                'regex:/^[a-z][a-z0-9_]*$/',
                Rule::unique('cms_user_fields')
                    ->where('user_type_id', $typeId)
                    ->where('parent_id', $parentId),
                Rule::notIn(UserField::RESERVED_KEYS),
            ],
            'type' => [
                'required',
                'string',
                Rule::in(UserField::FIELD_TYPES),
            ],
            'parent_id' => [
                'nullable',
                'integer',
                Rule::exists('cms_user_fields', 'id')
                    ->where('user_type_id', $typeId)
                    ->where('type', 'array'),
            ],
            'default_value' => ['nullable', 'string'],
            'data' => ['nullable', 'array'],
            'hint' => ['nullable', 'string', 'max:500'],
            'tab' => ['nullable', 'string', 'max:255'],
            'order' => ['integer'],
        ];

        // Дополнительная валидация data для img
        if ($this->input('type') === 'img') {
            $rules = array_merge($rules, [
                'data.sizes' => ['nullable', 'array'],
                'data.sizes.*' => ['array'],
                'data.sizes.*.width' => ['required', 'integer', 'min:1', 'max:10000'],
                'data.sizes.*.height' => ['nullable', 'integer', 'min:1', 'max:10000'],
                'data.sizes.*.fit' => ['required', 'string', 'in:cover,contain,crop,inside'],
                'data.formats' => ['nullable', 'array'],
                'data.formats.*' => ['string', 'in:original,webp,avif'],
                'data.quality' => ['nullable', 'integer', 'min:1', 'max:100'],
            ]);
        }

        // Дополнительная валидация data для select/radio
        if (in_array($this->input('type'), ['select', 'radio'])) {
            $rules = array_merge($rules, [
                'data.options' => ['nullable', 'array', 'min:1'],
                'data.options.*.value' => ['required', 'string'],
                'data.options.*.label' => ['required', 'string'],
            ]);
        }

        return $rules;
    }

    public function messages(): array
    {
        return [
            'key.regex' => 'Ключ должен начинаться с латинской буквы и содержать только строчные буквы, цифры и подчеркивания.',
            'key.unique' => 'Такой ключ уже существует в этом типе пользователя (на данном уровне вложенности).',
            'key.not_in' => 'Этот ключ зарезервирован системой.',
            'parent_id.exists' => 'Родительское поле должно быть типа array и принадлежать тому же типу пользователя.',
        ];
    }
}
