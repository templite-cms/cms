<?php

namespace Templite\Cms\Services;

use Illuminate\Support\Collection;
use Templite\Cms\Models\GlobalField;
use Templite\Cms\Models\GlobalFieldPage;
use Templite\Cms\Support\FieldValueCaster;

/**
 * Данные экрана значений глобальных настроек (`/cms/settings`): вкладки со
 * структурой и плоская карта «ключ поля → значение».
 *
 * Сборка живёт отдельно от контроллеров, потому что потребителей у неё два:
 * серверный рендер страницы (`Admin\GlobalSettingsController::index()`) и
 * эндпоинт перечитывания (`Api\GlobalSettingsController::form()`), которым
 * экран обновляет себя после структурных правок ассистента. Разойдись эти две
 * сборки — форма после обновления показывала бы не то же самое, что после F5,
 * и разницу пришлось бы ловить по симптомам.
 */
class GlobalSettingsFormData
{
    /**
     * @return array{
     *     pages: Collection<int,GlobalFieldPage>,
     *     values: array<string,mixed>
     * }
     */
    public function build(): array
    {
        $pages = GlobalFieldPage::with([
            'sections' => fn ($q) => $q->orderBy('order'),
            'sections.fields' => fn ($q) => $q->whereNull('parent_id')->orderBy('order'),
            'sections.fields.values' => fn ($q) => $q->whereNull('parent_id')->orderBy('order'),
            'sections.fields.values.allDescendants',
            'sections.fields.allChildren',
        ])->orderBy('order')->get();

        $values = [];

        foreach ($pages as $page) {
            foreach ($page->sections as $section) {
                foreach ($section->fields as $field) {
                    $values[$field->key] = $this->extractFieldValue($field);
                }
            }
        }

        return [
            'pages' => $pages,
            'values' => $values,
        ];
    }

    /**
     * Значение поля из уже подгруженной связи values.
     */
    private function extractFieldValue(GlobalField $field): mixed
    {
        if ($field->type !== 'array') {
            $value = $field->values->first();
            $raw = $value ? $value->value : ($field->default_value ?? '');

            return FieldValueCaster::cast($raw, $field->type);
        }

        $childFields = $field->allChildren;

        if ($childFields->isEmpty()) {
            return [];
        }

        return $this->buildRepeaterRows($field->values, $childFields);
    }

    /**
     * Строки повторителя из дерева значений — рекурсивно.
     *
     * @param  Collection<int,\Templite\Cms\Models\GlobalFieldValue>  $rowValues
     * @param  Collection<int,GlobalField>  $childFields
     * @return array<int,array<string,mixed>>
     */
    private function buildRepeaterRows(Collection $rowValues, Collection $childFields): array
    {
        $rows = [];

        foreach ($rowValues->sortBy('order') as $rowValue) {
            $row = [];
            // allDescendants = прямые дети этой строки-значения (у каждого свои allDescendants)
            $directChildValues = $rowValue->allDescendants ?? collect();

            foreach ($childFields as $childField) {
                $childVals = $directChildValues->where('global_field_id', $childField->id);

                if ($childField->type === 'array' && $childField->allChildren->isNotEmpty()) {
                    // Вложенный повторитель: childVals — маркеры строк, спускаемся глубже
                    $row[$childField->key] = $this->buildRepeaterRows($childVals, $childField->allChildren);
                } else {
                    $val = $childVals->first();
                    $row[$childField->key] = $val
                        ? FieldValueCaster::cast($val->value, $childField->type)
                        : null;
                }
            }

            $rows[] = $row;
        }

        return $rows;
    }
}
