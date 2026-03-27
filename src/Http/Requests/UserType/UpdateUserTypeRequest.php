<?php

namespace Templite\Cms\Http\Requests\UserType;

use Illuminate\Foundation\Http\FormRequest;

class UpdateUserTypeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $id = (int) $this->route('id');

        return [
            'name' => ['sometimes', 'string', 'max:255'],
            'slug' => [
                'sometimes',
                'string',
                'max:255',
                'unique:cms_user_types,slug,' . $id,
                'regex:/^[a-z][a-z0-9\-]*$/',
            ],
            'permissions' => ['nullable', 'array'],
            'settings' => ['nullable', 'array'],
            'is_active' => ['boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'slug.regex' => 'Slug должен начинаться с латинской буквы и содержать только строчные буквы, цифры и дефисы.',
            'slug.unique' => 'Такой slug уже существует.',
        ];
    }
}
