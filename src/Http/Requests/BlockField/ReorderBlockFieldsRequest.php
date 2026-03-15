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
            // Поддержка расширенного формата с order и block_section_id
            'items' => ['required', 'array', 'min:1'],
            'items.*.id' => ['required', 'integer', 'exists:block_fields,id'],
            'items.*.order' => ['required', 'integer', 'min:0'],
            'items.*.block_section_id' => ['nullable', 'integer', 'exists:block_sections,id'],
            'items.*.block_tab_id' => ['nullable', 'integer', 'exists:block_tabs,id'],
        ];
    }
}
