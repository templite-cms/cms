<?php

namespace Templite\Cms\Http\Requests\BlockField;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreBlockFieldRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $blockId = (int) $this->route('blockId');
        $parentId = $this->input('parent_id');

        $rules = [
            'name' => ['required', 'string', 'max:255'],
            'key' => [
                'required',
                'string',
                'max:64',
                'regex:/^[a-z][a-z0-9_]*$/',
                Rule::unique('block_fields')
                    ->where('fieldable_type', 'block')
                    ->where('fieldable_id', $blockId)
                    ->where('parent_id', $parentId),
                Rule::notIn([
                    'id', 'type', 'block', 'page', 'data', 'fields', 'global',
                    'actions', 'request', 'slot', 'attributes', 'errors',
                ]),
            ],
            'type' => [
                'required',
                'string',
                'in:text,textfield,number,img,file,editor,tiptap,html,select,checkbox,radio,link,date,datetime,array,color,page,user,category,product,product_option',
            ],
            'parent_id' => [
                'nullable',
                'integer',
                Rule::exists('block_fields', 'id')
                    ->where('fieldable_type', 'block')
                    ->where('fieldable_id', $blockId)
                    ->where('type', 'array'),
            ],
            'default_value' => ['nullable', 'string'],
            'data' => ['nullable', 'array'],
            'hint' => ['nullable', 'string', 'max:500'],
            'block_tab_id' => ['nullable', 'integer', 'exists:block_tabs,id'],
            'block_section_id' => ['nullable', 'integer', 'exists:block_sections,id'],
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
            'key.unique' => 'Такой ключ уже существует в этом блоке (на данном уровне вложенности).',
            'key.not_in' => 'Этот ключ зарезервирован системой.',
            'parent_id.exists' => 'Родительское поле должно быть типа array и принадлежать тому же блоку.',
        ];
    }
}
