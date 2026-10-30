<?php

namespace Templite\Cms\Http\Requests\BlockField;

use Illuminate\Foundation\Http\FormRequest;

class ReorderBlockFieldsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'items' => ['required', 'array', 'min:1'],
            'items.*.id' => ['required', 'integer', 'exists:block_fields,id'],
            'items.*.order' => ['required', 'integer', 'min:0'],
        ];
    }
}
