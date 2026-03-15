<?php

namespace Templite\Cms\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Templite\Cms\Http\Resources\PageTypeAttributeResource;
use Templite\Cms\Models\PageType;
use Templite\Cms\Models\PageTypeAttribute;

class PageTypeAttributeController extends Controller
{
    /** @OA\Get(path="/page-types/{id}/attributes", summary="Список атрибутов типа", tags={"Page Type Attributes"}, security={{"bearerAuth":{}}}, @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")), @OA\Response(response=200, description="Список")) */
    public function index(int $id): JsonResponse
    {
        $type = PageType::findOrFail($id);
        return $this->success(PageTypeAttributeResource::collection($type->attributes()->orderBy('order')->get()));
    }

    /** @OA\Post(path="/page-types/{id}/attributes", summary="Создать атрибут", tags={"Page Type Attributes"}, security={{"bearerAuth":{}}}, @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")), @OA\RequestBody(required=true, @OA\JsonContent(required={"name","key","type"}, @OA\Property(property="name", type="string"), @OA\Property(property="key", type="string"), @OA\Property(property="type", type="string"))), @OA\Response(response=201, description="Создано")) */
    public function store(Request $request, int $id): JsonResponse
    {
        $type = PageType::findOrFail($id);
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'key' => 'required|string|max:255',
            'type' => 'required|string|in:string,number,select,multi_select,boolean,date',
            'options' => 'nullable|array',
            'filterable' => 'boolean',
            'sortable' => 'boolean',
            'required' => 'boolean',
            'order' => 'integer',
        ]);
        $data['page_type_id'] = $type->id;
        $attr = PageTypeAttribute::create($data);
        $this->logAction('create', 'page_type_attribute', $attr->id, ['name' => $attr->name, 'key' => $attr->key]);
        return $this->success(new PageTypeAttributeResource($attr), 'Атрибут создан.', 201);
    }

    /** @OA\Put(path="/page-type-attributes/{id}", summary="Обновить атрибут", tags={"Page Type Attributes"}, security={{"bearerAuth":{}}}, @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")), @OA\Response(response=200, description="Обновлено")) */
    public function update(Request $request, int $id): JsonResponse
    {
        $attr = PageTypeAttribute::findOrFail($id);
        $data = $request->validate([
            'name' => 'sometimes|string|max:255',
            'key' => 'sometimes|string|max:255',
            'type' => 'sometimes|string|in:string,number,select,multi_select,boolean,date',
            'options' => 'nullable|array',
            'filterable' => 'boolean',
            'sortable' => 'boolean',
            'required' => 'boolean',
            'order' => 'integer',
        ]);
        $attr->update($data);
        $this->logAction('update', 'page_type_attribute', $attr->id, ['name' => $attr->name]);
        return $this->success(new PageTypeAttributeResource($attr->fresh()));
    }

    /** @OA\Delete(path="/page-type-attributes/{id}", summary="Удалить атрибут", tags={"Page Type Attributes"}, security={{"bearerAuth":{}}}, @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")), @OA\Response(response=200, description="Удалено")) */
    public function destroy(int $id): JsonResponse
    {
        $attr = PageTypeAttribute::findOrFail($id);
        $name = $attr->name;
        $attr->delete();
        $this->logAction('delete', 'page_type_attribute', $id, ['name' => $name]);
        return $this->success(null, 'Атрибут удалён.');
    }

    /** @OA\Put(path="/page-types/{id}/attributes/reorder", summary="Порядок атрибутов", tags={"Page Type Attributes"}, security={{"bearerAuth":{}}}, @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")), @OA\Response(response=200, description="Порядок обновлён")) */
    public function reorder(Request $request, int $id): JsonResponse
    {
        $data = $request->validate(['items' => 'required|array', 'items.*.id' => 'required|integer', 'items.*.order' => 'required|integer']);
        foreach ($data['items'] as $item) {
            PageTypeAttribute::where('id', $item['id'])->where('page_type_id', $id)->update(['order' => $item['order']]);
        }
        $this->logAction('reorder', 'page_type_attribute', null, ['page_type_id' => $id]);
        return $this->success(null, 'Порядок обновлён.');
    }
}
