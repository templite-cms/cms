<?php

namespace Templite\Cms\Services\Ai\Tools;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Templite\Cms\Models\Block;
use Templite\Cms\Services\Ai\EditorContext;
use Templite\Cms\Services\FieldableService;

class AddBlockFieldTool implements Tool
{
    /** Типы полей — как в StoreBlockFieldRequest. */
    private const TYPES = [
        'text', 'textfield', 'number', 'img', 'file', 'editor', 'tiptap', 'html',
        'select', 'checkbox', 'radio', 'link', 'date', 'datetime', 'array', 'color',
        'page', 'category', 'product', 'product_option',
    ];

    public function __construct(private readonly FieldableService $fields)
    {
    }

    public function name(): string
    {
        return 'add_block_field';
    }

    public function definition(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'block_id' => ['type' => 'integer'],
                'name' => ['type' => 'string', 'description' => 'Подпись поля в админке'],
                'key' => ['type' => 'string', 'description' => 'snake_case, начинается с буквы: button_text'],
                'type' => ['type' => 'string', 'enum' => self::TYPES],
                'hint' => ['type' => 'string'],
                'parent_id' => ['type' => 'integer', 'description' => 'ID поля типа array — для вложенных полей'],
            ],
            'required' => ['block_id', 'name', 'key', 'type'],
        ];
    }

    public function execute(array $args, ?EditorContext $ctx): array
    {
        $blockId = (int) ($args['block_id'] ?? 0);
        $block = Block::find($blockId);

        if (! $block) {
            return ['error' => 'block_not_found'];
        }

        $parentId = $args['parent_id'] ?? null;

        // Повторяем правила StoreBlockFieldRequest: контроллер типизирован под форм-реквест,
        // а инструмент вызывается вне HTTP-стека, поэтому валидируем сами.
        $validator = Validator::make([
            'name' => $args['name'] ?? null,
            'key' => $args['key'] ?? null,
            'type' => $args['type'] ?? null,
            'parent_id' => $parentId,
            'hint' => $args['hint'] ?? null,
        ], [
            'name' => ['required', 'string', 'max:255'],
            'key' => [
                'required', 'string', 'max:64', 'regex:/^[a-z][a-z0-9_]*$/',
                Rule::unique('block_fields')
                    ->where('fieldable_type', 'block')
                    ->where('fieldable_id', $blockId)
                    ->where('parent_id', $parentId),
                Rule::notIn(['id', 'type', 'block', 'page', 'data', 'fields', 'global',
                    'actions', 'request', 'slot', 'attributes', 'errors']),
            ],
            'type' => ['required', 'string', Rule::in(self::TYPES)],
            'parent_id' => [
                'nullable', 'integer',
                Rule::exists('block_fields', 'id')
                    ->where('fieldable_type', 'block')
                    ->where('fieldable_id', $blockId)
                    ->where('type', 'array'),
            ],
            'hint' => ['nullable', 'string', 'max:500'],
        ]);

        if ($validator->fails()) {
            return ['error' => 'validation_failed', 'details' => $validator->errors()->toArray()];
        }

        $data = array_filter($validator->validated(), fn ($v) => $v !== null);
        $field = $this->fields->createField($block, $data);

        return ['field_id' => $field->id, 'key' => $field->key];
    }
}
