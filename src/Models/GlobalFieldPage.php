<?php

namespace Templite\Cms\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Templite\Cms\Concerns\HasExportable;
use Templite\Cms\Contracts\Exportable;
use Templite\Cms\Services\ImportExport\ImportContext;
use Templite\Cms\Services\ImportExport\MediaFieldScanner;

class GlobalFieldPage extends Model implements Exportable
{
    use HasExportable;

    protected $fillable = [
        'name',
        'order',
        'columns',
        'column_widths',
    ];

    protected $casts = [
        'column_widths' => 'json',
    ];

    // --- Relationships ---

    public function sections(): HasMany
    {
        return $this->hasMany(GlobalFieldSection::class)->orderBy('order');
    }

    public function fields(): HasMany
    {
        return $this->hasMany(GlobalField::class)->orderBy('order');
    }

    // --- Exportable ---

    public function getExportType(): string { return 'global_field_page'; }
    public function getExportIdentifier(): string { return $this->name; }

    public function getDependencies(): array
    {
        $deps = [];
        $fileIds = $this->collectMediaFileIds();
        if (!empty($fileIds)) {
            $files = File::whereIn('id', $fileIds)->get();
            foreach ($files as $file) {
                $deps[] = $file;
            }
        }
        return $deps;
    }

    public function toExportArray(): array
    {
        $exportedFields = $this->exportGlobalFields($this->fields->whereNull('parent_id'));

        // Extract file IDs from exported fields and replace with paths
        $fileIds = MediaFieldScanner::extractFileIdsFromExportedGlobalFields($exportedFields);
        if (!empty($fileIds)) {
            $fileMap = File::whereIn('id', $fileIds)->get()->keyBy('id');
            $exportedFields = MediaFieldScanner::replaceGlobalFieldIdsWithPaths($exportedFields, $fileMap);
        }

        return [
            'name' => $this->name,
            'order' => $this->order,
            'columns' => $this->columns,
            'column_widths' => $this->column_widths,
            'sections' => $this->sections->sortBy('order')->map(fn ($s) => [
                'name' => $s->name,
                'order' => $s->order,
                'column_index' => $s->column_index,
            ])->values()->toArray(),
            'fields' => $exportedFields,
        ];
    }

    protected function exportGlobalFields($fields, ?array $rowMarkerIds = null): array
    {
        return $fields->sortBy('order')->map(function ($f) use ($rowMarkerIds) {
            // Build index map: row marker value ID → row index
            // Used for child fields to associate values with repeater rows
            $parentIdToRowIndex = null;
            if ($rowMarkerIds !== null) {
                $parentIdToRowIndex = array_flip($rowMarkerIds);
            }

            $arr = [
                'name' => $f->name,
                'key' => $f->key,
                'type' => $f->type,
                'default_value' => $f->default_value,
                'data' => $f->data,
                'order' => $f->order,
                'section_name' => $f->section?->name,
                'values' => $f->values->map(function ($v) use ($parentIdToRowIndex) {
                    $exported = [
                        'value' => $v->value,
                        'order' => $v->order,
                        'translations' => $v->translations->map(fn ($t) => [
                            'lang' => $t->lang,
                            'value' => $t->value,
                        ])->toArray(),
                    ];
                    // For child values in a repeater, save the row index
                    if ($parentIdToRowIndex !== null && $v->parent_id !== null) {
                        $exported['row_index'] = $parentIdToRowIndex[$v->parent_id] ?? null;
                    }
                    return $exported;
                })->toArray(),
            ];

            if ($f->children->isNotEmpty()) {
                // Collect row marker IDs from repeater field values (ordered)
                $childRowMarkerIds = $f->values->sortBy('order')->pluck('id')->toArray();
                $arr['children'] = $this->exportGlobalFields($f->children, $childRowMarkerIds);
            }

            return $arr;
        })->values()->toArray();
    }

    public static function fromImportArray(array $data, ImportContext $ctx): static
    {
        $page = static::updateOrCreate(
            ['name' => $data['name']],
            [
                'order' => $data['order'] ?? 0,
                'columns' => $data['columns'] ?? 1,
                'column_widths' => $data['column_widths'] ?? null,
            ]
        );

        // Re-create sections
        $page->sections()->delete();
        $sectionMap = [];
        foreach ($data['sections'] ?? [] as $secData) {
            $section = $page->sections()->create($secData);
            $sectionMap[$secData['name']] = $section->id;
        }

        // Remap file paths back to IDs in fields data before importing
        $fieldsData = $data['fields'] ?? [];
        if (!empty($fieldsData)) {
            $fieldsData = MediaFieldScanner::replaceGlobalFieldPathsWithIds($fieldsData, $ctx);
        }

        // Re-create fields with values
        $page->fields()->delete();
        static::importGlobalFields($page, $fieldsData, $sectionMap);

        return $page;
    }

    protected static function importGlobalFields(
        GlobalFieldPage $page,
        array $fields,
        array $sectionMap,
        ?int $parentId = null,
        array $rowMarkerMap = []
    ): void {
        foreach ($fields as $fData) {
            $sectionId = isset($fData['section_name'])
                ? ($sectionMap[$fData['section_name']] ?? null)
                : null;

            $field = $page->fields()->create([
                'name' => $fData['name'],
                'key' => $fData['key'],
                'type' => $fData['type'],
                'default_value' => $fData['default_value'] ?? null,
                'data' => $fData['data'] ?? null,
                'order' => $fData['order'] ?? 0,
                'parent_id' => $parentId,
                'global_field_section_id' => $sectionId,
            ]);

            // Create values
            $currentRowMarkerMap = [];
            foreach ($fData['values'] ?? [] as $index => $vData) {
                // Resolve parent_id from row_index (for repeater child values)
                $valueParentId = null;
                if (isset($vData['row_index']) && isset($rowMarkerMap[$vData['row_index']])) {
                    $valueParentId = $rowMarkerMap[$vData['row_index']];
                }

                $value = $field->values()->create([
                    'value' => $vData['value'],
                    'order' => $vData['order'] ?? 0,
                    'parent_id' => $valueParentId,
                ]);

                // Create translations
                foreach ($vData['translations'] ?? [] as $tData) {
                    $value->translations()->create($tData);
                }

                // Track row markers for repeater fields (values with null value)
                $currentRowMarkerMap[$index] = $value->id;
            }

            // Recursive children — pass row marker map so child values get linked
            if (!empty($fData['children'])) {
                static::importGlobalFields($page, $fData['children'], $sectionMap, $field->id, $currentRowMarkerMap);
            }
        }
    }

    /**
     * Получить медиафайлы для включения в ZIP-архив экспорта.
     *
     * Сканирует все глобальные поля типа img/file на наличие файлов,
     * загружает File-модели и собирает все физические пути
     * (оригинал + ресайзы + webp-варианты).
     *
     * @return string[]
     */
    public function getExportMediaFiles(): array
    {
        $fileIds = $this->collectMediaFileIds();
        if (empty($fileIds)) {
            return [];
        }

        $files = File::whereIn('id', $fileIds)->get();
        $paths = [];
        foreach ($files as $file) {
            $paths = array_merge($paths, $file->getExportMediaFiles());
        }

        return array_values(array_unique($paths));
    }

    /**
     * Собрать все file ID из значений глобальных полей типа img/file.
     *
     * Используется в getDependencies(), toExportArray() и getExportMediaFiles().
     *
     * @return int[]
     */
    /** @var int[]|null Кэш собранных file ID */
    protected ?array $cachedMediaFileIds = null;

    protected function collectMediaFileIds(): array
    {
        if ($this->cachedMediaFileIds !== null) {
            return $this->cachedMediaFileIds;
        }

        $ids = [];

        $fields = $this->fields()->with(['values.translations', 'children.values.translations'])->get();

        foreach ($fields as $field) {
            $this->collectFileIdsFromField($field, $ids);
        }

        return $this->cachedMediaFileIds = array_values(array_unique($ids));
    }

    /**
     * Рекурсивно собрать file ID из одного глобального поля и его детей.
     *
     * @param GlobalField $field
     * @param int[] &$ids
     */
    protected function collectFileIdsFromField(GlobalField $field, array &$ids): void
    {
        if ($field->isFileField()) {
            foreach ($field->values as $val) {
                if (is_numeric($val->value)) {
                    $ids[] = (int) $val->value;
                }
                foreach ($val->translations as $t) {
                    if (is_numeric($t->value)) {
                        $ids[] = (int) $t->value;
                    }
                }
            }
        }

        foreach ($field->children as $child) {
            $this->collectFileIdsFromField($child, $ids);
        }
    }
}
