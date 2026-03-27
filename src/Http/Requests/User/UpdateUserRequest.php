<?php

namespace Templite\Cms\Http\Requests\User;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Templite\Cms\Models\User;

class UpdateUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $userId = (int) $this->route('id');
        $user = User::findOrFail($userId);

        return [
            'user_type_id' => ['sometimes', 'integer', 'exists:cms_user_types,id'],
            'name' => ['sometimes', 'string', 'max:255'],
            'email' => [
                'sometimes',
                'email',
                'max:255',
                Rule::unique('cms_users', 'email')
                    ->where('user_type_id', $this->input('user_type_id', $user->user_type_id))
                    ->ignore($userId),
            ],
            'password' => ['nullable', 'string', 'min:6'],
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
