<?php

namespace Templite\Cms\Http\Requests\UserType;

use Illuminate\Foundation\Http\FormRequest;

class StoreUserTypeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'slug' => [
                'required',
                'string',
                'max:255',
                'unique:cms_user_types,slug',
                'regex:/^[a-z][a-z0-9\-]*$/',
            ],
            'guard' => ['required', 'string', 'max:100', 'unique:cms_user_types,guard'],
            'module' => ['required', 'string', 'max:100'],
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
            'guard.unique' => 'Такой guard уже зарегистрирован.',
        ];
    }
}
