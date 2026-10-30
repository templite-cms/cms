<?php

namespace Templite\Cms\Services\Ai\Tools;

use Templite\Cms\Models\BlockField;
use Templite\Cms\Services\Ai\EditorContext;

class UpdateBlockFieldTool implements Tool
{
    /** Типы полей — как в StoreBlockFieldRequest. */
    private const TYPES = [
        'text', 'textfield', 'number', 'img', 'file', 'editor', 'tiptap', 'html',
        'select', 'checkbox', 'radio', 'link', 'date', 'datetime', 'array', 'color',
        'page', 'category', 'product', 'product_option',
    ];

    public function name(): string
    {
        return 'update_block_field';
    }

    public function definition(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'field_id' => ['type' => 'integer'],
                'name' => ['type' => 'string'],
                'type' => ['type' => 'string', 'enum' => self::TYPES],
                'hint' => ['type' => 'string'],
            ],
            'required' => ['field_id'],
        ];
    }

    public function execute(array $args, ?EditorContext $ctx): array
    {
        $field = BlockField::find((int) ($args['field_id'] ?? 0));

        if (! $field) {
            return ['error' => 'field_not_found'];
        }

        if (isset($args['type']) && ! in_array($args['type'], self::TYPES, true)) {
            return ['error' => 'invalid_type', 'message' => 'Недопустимый тип поля.'];
        }

        // key не меняем: он уже используется в шаблоне блока и в данных страниц.
        $update = array_filter([
            'name' => $args['name'] ?? null,
            'type' => $args['type'] ?? null,
            'hint' => $args['hint'] ?? null,
        ], fn ($v) => $v !== null);

        if ($update === []) {
            return ['error' => 'nothing_to_update'];
        }

        $field->update($update);

        return ['field_id' => $field->id, 'status' => 'updated'];
    }
}
