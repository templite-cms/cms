<?php

namespace Templite\Cms\Services\Ai\Tools;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Templite\Cms\Models\TemplatePage;
use Templite\Cms\Services\Ai\EditorContext;
use Templite\Cms\Services\FieldableService;

/**
 * Поле шаблона — настройка, общая для всех страниц на нём: телефон в шапке,
 * тексты подвала, логотип. В Blade шаблона доступно как $fields['ключ'].
 */
class AddTemplateFieldTool implements Tool
{
    /** Типы полей — как в StoreTemplateFieldRequest. */
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
        return 'add_template_field';
    }

    public function definition(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'template_id' => ['type' => 'integer', 'description' => 'ID шаблона из list_templates'],
                'name' => ['type' => 'string', 'description' => 'Подпись поля в админке'],
                'key' => ['type' => 'string', 'description' => 'snake_case, начинается с буквы: footer_text'],
                'type' => ['type' => 'string', 'enum' => self::TYPES],
                'hint' => ['type' => 'string'],
                'parent_id' => ['type' => 'integer', 'description' => 'ID поля типа array — для вложенных полей'],
            ],
            'required' => ['template_id', 'name', 'key', 'type'],
        ];
    }

    public function execute(array $args, ?EditorContext $ctx): array
    {
        $templateId = (int) ($args['template_id'] ?? 0);
        $template = TemplatePage::find($templateId);

        if (!$template) {
            return ['error' => 'template_not_found', 'template_id' => $templateId];
        }

        $parentId = $args['parent_id'] ?? null;

        // Повторяем правила StoreTemplateFieldRequest: контроллер типизирован
        // под форм-реквест, а инструмент работает вне HTTP-стека.
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
                    ->where('fieldable_type', 'template_page')
                    ->where('fieldable_id', $templateId)
                    ->where('parent_id', $parentId),
                Rule::notIn(['id', 'type', 'block', 'page', 'data', 'fields', 'global',
                    'actions', 'request', 'slot', 'attributes', 'errors']),
            ],
            'type' => ['required', 'string', Rule::in(self::TYPES)],
            'parent_id' => [
                'nullable', 'integer',
                Rule::exists('block_fields', 'id')
                    ->where('fieldable_type', 'template_page')
                    ->where('fieldable_id', $templateId)
                    ->where('type', 'array'),
            ],
            'hint' => ['nullable', 'string', 'max:500'],
        ]);

        if ($validator->fails()) {
            return ['error' => 'validation_failed', 'details' => $validator->errors()->toArray()];
        }

        $data = array_filter($validator->validated(), fn ($value) => $value !== null);
        $field = $this->fields->createField($template, $data);

        return [
            'field_id' => $field->id,
            'key' => $field->key,
            'usage' => "В Blade шаблона значение доступно как \$fields['{$field->key}'].",
        ];
    }
}
