<?php

namespace Templite\Cms\Http\Controllers\Admin;

use Illuminate\Routing\Controller;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;
use Templite\Cms\Models\CmsConfig;
use Templite\Cms\Models\GlobalField;
use Templite\Cms\Models\GlobalFieldPage;
use Templite\Cms\Models\Language;

class GlobalSettingsController extends Controller
{
    /**
     * Глобальные настройки (все вкладки, секции, поля, значения).
     * Экран: Settings/Index
     */
    public function index(): Response
    {
        $pages = GlobalFieldPage::with([
            'sections' => fn ($q) => $q->orderBy('order'),
            'sections.fields' => fn ($q) => $q->whereNull('parent_id')->orderBy('order'),
            'sections.fields.values' => fn ($q) => $q->whereNull('parent_id')->orderBy('order'),
            'sections.fields.values.allDescendants',
            'sections.fields.allChildren',
        ])->orderBy('order')->get();

        // Build flat values map: { fieldKey => value }
        // Build valueIdMap: { fieldKey => globalFieldValueId } for translations
        $values = [];
        $valueIdMap = [];
        foreach ($pages as $page) {
            foreach ($page->sections as $section) {
                foreach ($section->fields as $field) {
                    $values[$field->key] = $this->extractFieldValue($field);
                    $firstValue = $field->values->first();
                    if ($firstValue) {
                        $valueIdMap[$field->key] = $firstValue->id;
                    }
                }
            }
        }

        $multilangEnabled = (bool) CmsConfig::getValue('multilang_enabled', false);

        return Inertia::render('Settings/Index', [
            'pages' => $pages,
            'values' => $values,
            'valueIdMap' => $valueIdMap,
            'fieldTypes' => config('cms.field_types', [
                'text', 'textfield', 'number', 'img', 'file', 'editor', 'html',
                'select', 'checkbox', 'radio', 'link', 'date', 'datetime',
                'array', 'category', 'product', 'product_option', 'color',
            ]),
            'multilanguageEnabled' => $multilangEnabled,
            'languages' => $multilangEnabled
                ? Language::active()->ordered()->get()
                : [],
        ]);
    }

    /**
     * Extract a field's value from its loaded values relationship.
     */
    private function extractFieldValue(GlobalField $field): mixed
    {
        if ($field->type !== 'array') {
            $value = $field->values->first();
            $raw = $value ? $value->value : ($field->default_value ?? '');

            return \Templite\Cms\Support\FieldValueCaster::cast($raw, $field->type);
        }

        $childFields = $field->allChildren;
        if ($childFields->isEmpty()) return [];

        return $this->buildRepeaterRows($field->values, $childFields);
    }

    /**
     * Recursively build repeater row data from value tree.
     */
    private function buildRepeaterRows(Collection $rowValues, Collection $childFields): array
    {
        $rows = [];
        foreach ($rowValues->sortBy('order') as $rowValue) {
            $row = [];
            // allDescendants = direct children of this row value (each with their own allDescendants)
            $directChildValues = $rowValue->allDescendants ?? collect();

            foreach ($childFields as $childField) {
                $childVals = $directChildValues->where('global_field_id', $childField->id);

                if ($childField->type === 'array' && $childField->allChildren->isNotEmpty()) {
                    // Nested array: childVals are row markers → recurse
                    $row[$childField->key] = $this->buildRepeaterRows($childVals, $childField->allChildren);
                } else {
                    $val = $childVals->first();
                    $row[$childField->key] = $val
                        ? \Templite\Cms\Support\FieldValueCaster::cast($val->value, $childField->type)
                        : null;
                }
            }

            $rows[] = $row;
        }
        return $rows;
    }

    /**
     * Структура глобальных настроек (CRUD вкладок, секций, полей).
     * Экран: Settings/Structure
     */
    public function structure(): Response
    {
        $pages = GlobalFieldPage::with([
            'sections' => fn ($q) => $q->orderBy('order'),
            'sections.fields' => fn ($q) => $q->whereNull('parent_id')->orderBy('order'),
            'sections.fields.allChildren',
        ])->orderBy('order')->get();

        return Inertia::render('Settings/Structure', [
            'pages' => $pages,
            'fieldTypes' => config('cms.field_types', [
                'text', 'textfield', 'number', 'img', 'file', 'editor', 'html',
                'select', 'checkbox', 'radio', 'link', 'date', 'datetime',
                'array', 'category', 'product', 'product_option', 'color',
            ]),
        ]);
    }
}
