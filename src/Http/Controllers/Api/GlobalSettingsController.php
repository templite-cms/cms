<?php

namespace Templite\Cms\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Templite\Cms\Http\Resources\GlobalFieldResource;
use Templite\Cms\Models\GlobalField;
use Templite\Cms\Models\GlobalFieldPage;
use Templite\Cms\Models\GlobalFieldSection;
use Templite\Cms\Models\GlobalFieldValue;
use Templite\Cms\Services\CacheManager;

class GlobalSettingsController extends Controller
{
    public function __construct(protected CacheManager $cacheManager) {}

    /** @OA\Get(path="/settings", summary="Все настройки", tags={"Global Settings"}, security={{"bearerAuth":{}}}, @OA\Response(response=200, description="Настройки")) */
    public function index(): JsonResponse
    {
        $pages = GlobalFieldPage::with(['sections.fields.values', 'fields.values.children', 'fields.children.values'])
            ->orderBy('order')
            ->get();
        return $this->success($pages);
    }

    /** @OA\Get(path="/settings/pages", summary="Вкладки настроек", tags={"Global Settings"}, security={{"bearerAuth":{}}}, @OA\Response(response=200, description="Вкладки")) */
    public function pages(): JsonResponse
    {
        return $this->success(GlobalFieldPage::orderBy('order')->get());
    }

    /** @OA\Post(path="/settings/pages", summary="Создать вкладку", tags={"Global Settings"}, security={{"bearerAuth":{}}}, @OA\Response(response=201, description="Создано")) */
    public function storePage(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'order' => 'integer',
            'columns' => 'integer|min:1|max:4',
            'column_widths' => 'nullable|array',
            'column_widths.*' => 'numeric|min:10|max:100',
        ]);
        $page = GlobalFieldPage::create($data);

        $this->logAction('create', 'global_settings', $page->id, ['name' => $page->name, 'entity' => 'page']);

        return $this->success($page, 'Вкладка создана.', 201);
    }

    /** @OA\Put(path="/settings/pages/{id}", summary="Обновить вкладку", tags={"Global Settings"}, security={{"bearerAuth":{}}}, @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")), @OA\Response(response=200, description="Обновлено")) */
    public function updatePage(Request $request, int $id): JsonResponse
    {
        $page = GlobalFieldPage::findOrFail($id);
        $page->update($request->validate([
            'name' => 'sometimes|string|max:255',
            'order' => 'integer',
            'columns' => 'integer|min:1|max:4',
            'column_widths' => 'nullable|array',
            'column_widths.*' => 'numeric|min:10|max:100',
        ]));

        $this->logAction('update', 'global_settings', $page->id, ['name' => $page->name, 'entity' => 'page']);

        return $this->success($page->fresh());
    }

    /** @OA\Delete(path="/settings/pages/{id}", summary="Удалить вкладку", tags={"Global Settings"}, security={{"bearerAuth":{}}}, @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")), @OA\Response(response=200, description="Удалено")) */
    public function destroyPage(int $id): JsonResponse
    {
        $page = GlobalFieldPage::findOrFail($id);
        $name = $page->name;
        $page->delete();

        $this->logAction('delete', 'global_settings', $id, ['name' => $name, 'entity' => 'page']);

        return $this->success(null, 'Вкладка удалена.');
    }

    /** @OA\Post(path="/settings/sections", summary="Создать секцию", tags={"Global Settings"}, security={{"bearerAuth":{}}}, @OA\Response(response=201, description="Создано")) */
    public function storeSection(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'global_field_page_id' => 'required|integer|exists:global_field_pages,id',
            'order' => 'integer',
            'column_index' => 'integer|min:0|max:3',
        ]);
        $section = GlobalFieldSection::create($data);

        $this->logAction('create', 'global_settings', $section->id, ['name' => $section->name, 'entity' => 'section']);

        return $this->success($section, 'Секция создана.', 201);
    }

    /** @OA\Put(path="/settings/sections/{id}", summary="Обновить секцию", tags={"Global Settings"}, security={{"bearerAuth":{}}}, @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")), @OA\Response(response=200, description="Обновлено")) */
    public function updateSection(Request $request, int $id): JsonResponse
    {
        $section = GlobalFieldSection::findOrFail($id);
        $section->update($request->validate([
            'name' => 'sometimes|string|max:255',
            'order' => 'integer',
            'column_index' => 'integer|min:0|max:3',
        ]));

        $this->logAction('update', 'global_settings', $section->id, ['name' => $section->name, 'entity' => 'section']);

        return $this->success($section->fresh());
    }

    /** @OA\Delete(path="/settings/sections/{id}", summary="Удалить секцию", tags={"Global Settings"}, security={{"bearerAuth":{}}}, @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")), @OA\Response(response=200, description="Удалено")) */
    public function destroySection(int $id): JsonResponse
    {
        $section = GlobalFieldSection::findOrFail($id);
        $name = $section->name;
        $section->delete();

        $this->logAction('delete', 'global_settings', $id, ['name' => $name, 'entity' => 'section']);

        return $this->success(null, 'Секция удалена.');
    }

    /** @OA\Post(path="/settings/fields", summary="Создать поле", tags={"Global Settings"}, security={{"bearerAuth":{}}}, @OA\Response(response=201, description="Создано")) */
    public function storeField(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => 'required|string|max:255', 'type' => 'required|string', 'key' => 'required|string|max:255',
            'parent_id' => 'nullable|integer|exists:global_fields,id',
            'default_value' => 'nullable|string', 'data' => 'nullable|array',
            'global_field_page_id' => 'nullable|integer|exists:global_field_pages,id',
            'global_field_section_id' => 'nullable|integer|exists:global_field_sections,id', 'order' => 'integer',
        ]);
        $field = GlobalField::create($data);

        $this->logAction('create', 'global_settings', $field->id, ['name' => $field->name, 'key' => $field->key, 'entity' => 'field']);

        return $this->success(new GlobalFieldResource($field), 'Поле создано.', 201);
    }

    /** @OA\Put(path="/settings/fields/{id}", summary="Обновить поле", tags={"Global Settings"}, security={{"bearerAuth":{}}}, @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")), @OA\Response(response=200, description="Обновлено")) */
    public function updateField(Request $request, int $id): JsonResponse
    {
        $field = GlobalField::findOrFail($id);
        $data = $request->validate([
            'name' => 'sometimes|string|max:255', 'type' => 'sometimes|string', 'key' => 'sometimes|string',
            'default_value' => 'nullable|string', 'data' => 'nullable|array', 'order' => 'integer',
        ]);
        $field->update($data);

        $this->logAction('update', 'global_settings', $field->id, ['name' => $field->name, 'key' => $field->key, 'entity' => 'field']);

        return $this->success(new GlobalFieldResource($field->fresh(['values', 'children'])));
    }

    /** @OA\Delete(path="/settings/fields/{id}", summary="Удалить поле", tags={"Global Settings"}, security={{"bearerAuth":{}}}, @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")), @OA\Response(response=200, description="Удалено")) */
    public function destroyField(int $id): JsonResponse
    {
        $field = GlobalField::findOrFail($id);
        $name = $field->name;
        $field->delete();

        $this->logAction('delete', 'global_settings', $id, ['name' => $name, 'entity' => 'field']);

        return $this->success(null, 'Поле удалено.');
    }

    /** @OA\Put(path="/settings/fields/reorder", summary="Сортировка полей", tags={"Global Settings"}, security={{"bearerAuth":{}}}, @OA\RequestBody(required=true, @OA\JsonContent(@OA\Property(property="items", type="array", @OA\Items(@OA\Property(property="id", type="integer"), @OA\Property(property="order", type="integer"), @OA\Property(property="global_field_section_id", type="integer", nullable=true), @OA\Property(property="global_field_page_id", type="integer", nullable=true))))), @OA\Response(response=200, description="Порядок обновлён")) */
    public function reorderFields(Request $request): JsonResponse
    {
        $data = $request->validate([
            'items' => 'required|array',
            'items.*.id' => 'required|integer|exists:global_fields,id',
            'items.*.order' => 'required|integer|min:0',
            'items.*.global_field_section_id' => 'nullable|integer|exists:global_field_sections,id',
            'items.*.global_field_page_id' => 'nullable|integer|exists:global_field_pages,id',
        ]);

        foreach ($data['items'] as $item) {
            $update = ['order' => $item['order']];
            if (array_key_exists('global_field_section_id', $item)) {
                $update['global_field_section_id'] = $item['global_field_section_id'];
            }
            if (array_key_exists('global_field_page_id', $item)) {
                $update['global_field_page_id'] = $item['global_field_page_id'];
            }
            GlobalField::where('id', $item['id'])->update($update);
        }

        $this->logAction('reorder', 'global_settings', null, ['entity' => 'field', 'count' => count($data['items'])]);

        return $this->success(null, 'Порядок полей обновлён.');
    }

    /** @OA\Put(path="/settings/pages/reorder", summary="Сортировка вкладок", tags={"Global Settings"}, security={{"bearerAuth":{}}}, @OA\RequestBody(required=true, @OA\JsonContent(@OA\Property(property="ids", type="array", @OA\Items(type="integer")))), @OA\Response(response=200, description="Порядок обновлён")) */
    public function reorderPages(Request $request): JsonResponse
    {
        $data = $request->validate([
            'ids' => 'required|array',
            'ids.*' => 'integer|exists:global_field_pages,id',
        ]);

        foreach ($data['ids'] as $index => $id) {
            GlobalFieldPage::where('id', $id)->update(['order' => $index]);
        }

        $this->logAction('reorder', 'global_settings', null, ['entity' => 'page', 'count' => count($data['ids'])]);

        return $this->success(null, 'Порядок вкладок обновлён.');
    }

    /** @OA\Put(path="/settings/sections/reorder", summary="Сортировка секций", tags={"Global Settings"}, security={{"bearerAuth":{}}}, @OA\RequestBody(required=true, @OA\JsonContent(@OA\Property(property="items", type="array", @OA\Items(@OA\Property(property="id", type="integer"), @OA\Property(property="order", type="integer"))))), @OA\Response(response=200, description="Порядок обновлён")) */
    public function reorderSections(Request $request): JsonResponse
    {
        $data = $request->validate([
            'items' => 'required|array',
            'items.*.id' => 'required|integer|exists:global_field_sections,id',
            'items.*.order' => 'required|integer|min:0',
            'items.*.column_index' => 'integer|min:0|max:3',
        ]);

        foreach ($data['items'] as $item) {
            $update = ['order' => $item['order']];
            if (array_key_exists('column_index', $item)) {
                $update['column_index'] = $item['column_index'];
            }
            GlobalFieldSection::where('id', $item['id'])->update($update);
        }

        $this->logAction('reorder', 'global_settings', null, ['entity' => 'section', 'count' => count($data['items'])]);

        return $this->success(null, 'Порядок секций обновлён.');
    }

    /** @OA\Put(path="/settings/values", summary="Сохранить все значения", tags={"Global Settings"}, security={{"bearerAuth":{}}}, @OA\RequestBody(required=true, @OA\JsonContent(@OA\Property(property="values", type="object"))), @OA\Response(response=200, description="Сохранено")) */
    public function saveValues(Request $request): JsonResponse
    {
        $data = $request->validate(['values' => 'required|array']);

        // Build key->id map for resolving field keys to IDs
        $fieldMap = GlobalField::pluck('id', 'key')->toArray();

        foreach ($data['values'] as $fieldKey => $value) {
            // Resolve: if numeric use directly, otherwise look up by key
            $fieldId = is_numeric($fieldKey) ? (int) $fieldKey : ($fieldMap[$fieldKey] ?? null);
            if (!$fieldId) continue;

            if (is_array($value)) {
                // Повторитель: удаляем старые и создаём новые
                GlobalFieldValue::where('global_field_id', $fieldId)->whereNull('parent_id')->delete();
                foreach ($value as $index => $item) {
                    if (is_array($item)) {
                        $parentValue = GlobalFieldValue::create([
                            'global_field_id' => $fieldId, 'value' => null, 'order' => $index,
                        ]);
                        foreach ($item as $childKey => $childValue) {
                            $childFieldId = is_numeric($childKey) ? (int) $childKey : ($fieldMap[$childKey] ?? null);
                            if (!$childFieldId) continue;
                            GlobalFieldValue::create([
                                'global_field_id' => $childFieldId,
                                'parent_id' => $parentValue->id,
                                'value' => is_string($childValue) ? $childValue : json_encode($childValue),
                                'order' => 0,
                            ]);
                        }
                    }
                }
            } else {
                GlobalFieldValue::updateOrCreate(
                    ['global_field_id' => $fieldId, 'parent_id' => null],
                    ['value' => $value]
                );
            }
        }

        $this->cacheManager->invalidateGlobalFields();

        $this->logAction('save_values', 'global_settings', null, ['keys' => array_keys($data['values'])]);

        return $this->success(null, 'Значения сохранены.');
    }

    /**
     * Получить шаблон preview wrapper.
     */
    public function getPreviewWrapper(): JsonResponse
    {
        $custom = \Templite\Cms\Models\CmsConfig::getValue('preview_wrapper');

        if ($custom) {
            return $this->success(['content' => $custom, 'is_custom' => true]);
        }

        // Возвращаем дефолтный шаблон из vendor
        $default = file_get_contents(
            dirname(__DIR__, 4) . '/resources/views/render/preview.blade.php'
        );

        return $this->success(['content' => $default, 'is_custom' => false]);
    }

    /**
     * Сохранить кастомный шаблон preview wrapper.
     */
    public function savePreviewWrapper(Request $request): JsonResponse
    {
        $data = $request->validate([
            'content' => 'required|string',
        ]);

        \Templite\Cms\Models\CmsConfig::updateOrCreate(
            ['key' => 'preview_wrapper'],
            [
                'value' => $data['content'],
                'type' => 'string',
                'group' => 'system',
                'label' => 'Preview Wrapper Template',
            ]
        );

        return $this->success(null, 'Шаблон превью сохранён.');
    }

    /**
     * Сбросить preview wrapper к дефолтному.
     */
    public function resetPreviewWrapper(): JsonResponse
    {
        \Templite\Cms\Models\CmsConfig::where('key', 'preview_wrapper')->delete();

        return $this->success(null, 'Шаблон превью сброшен.');
    }
}
