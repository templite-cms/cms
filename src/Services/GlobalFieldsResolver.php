<?php

namespace Templite\Cms\Services;

use Templite\Cms\Models\GlobalField;
use Templite\Cms\Models\GlobalFieldValue;
use Templite\Cms\Support\FieldValueCaster;

/**
 * Сборка глобальных полей в плоскую карту `ключ => значение` — ровно ту,
 * что уходит в Blade как $global и в actions как $context->global.
 *
 * Логика переехала из GlobalFieldsMiddleware: её вторым потребителем стали
 * инструменты ассистента, а расхождение между тем, что агент читает, и тем,
 * что рендерится на странице, — худший из возможных багов этой области.
 */
class GlobalFieldsResolver
{
    /**
     * @return array<string,mixed>
     */
    public function resolve(): array
    {
        $fields = GlobalField::with(['values', 'children.values'])
            ->whereNull('parent_id')
            ->get();

        $result = [];

        foreach ($fields as $field) {
            $result[$field->key] = $field->isRepeater()
                ? $this->resolveRepeaterValues($field)
                : FieldValueCaster::cast($field->getValue(), $field->type);
        }

        return $result;
    }

    /**
     * Резолв значений повторителя. Один уровень: вложенный повторитель
     * здесь не разворачивается — его не умеет сохранять и сам saveValues().
     *
     * @return array<int,array<string,mixed>>
     */
    protected function resolveRepeaterValues(GlobalField $field): array
    {
        $parentValues = GlobalFieldValue::where('global_field_id', $field->id)
            ->whereNull('parent_id')
            ->orderBy('order')
            ->get();

        $items = [];

        foreach ($parentValues as $parentValue) {
            $item = [];
            $childValues = GlobalFieldValue::where('parent_id', $parentValue->id)->get();

            foreach ($childValues as $childValue) {
                $childField = $field->children->firstWhere('id', $childValue->global_field_id);
                if ($childField) {
                    $item[$childField->key] = FieldValueCaster::cast($childValue->value, $childField->type);
                }
            }

            $items[] = $item;
        }

        return $items;
    }
}
