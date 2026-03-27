<?php

namespace Templite\Cms\Http\Requests\User;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'user_type_id' => ['required', 'integer', 'exists:cms_user_types,id'],
            'name' => ['required', 'string', 'max:255'],
            'email' => [
                'required',
                'email',
                'max:255',
                Rule::unique('cms_users', 'email')
                    ->where('user_type_id', $this->input('user_type_id')),
            ],
            'password' => ['required', 'string', 'min:6'],
            'avatar_id' => ['nullable', 'integer', 'exists:files,id'],
            'data' => ['nullable', 'array'],
            'settings' => ['nullable', 'array'],
            'is_active' => ['boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'email.unique' => 'Пользователь с таким email уже существует в этом типе.',
        ];
    }
}
