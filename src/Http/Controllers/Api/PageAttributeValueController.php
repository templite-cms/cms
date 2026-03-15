<?php

namespace Templite\Cms\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Templite\Cms\Models\Page;
use Templite\Cms\Models\PageAttributeValue;

class PageAttributeValueController extends Controller
{
    /** @OA\Get(path="/pages/{id}/attributes", summary="Значения атрибутов страницы", tags={"Page Type Attributes"}, security={{"bearerAuth":{}}}, @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")), @OA\Response(response=200, description="Значения")) */
    public function index(int $id): JsonResponse
    {
        $page = Page::findOrFail($id);
        $values = PageAttributeValue::where('page_id', $page->id)->with('attribute')->get();
        $result = [];
        foreach ($values as $v) {
            $result[$v->attribute->key ?? $v->attribute_id] = $v->value;
        }
        return $this->success($result);
    }

    /** @OA\Put(path="/pages/{id}/attributes", summary="Сохранить значения атрибутов", tags={"Page Type Attributes"}, security={{"bearerAuth":{}}}, @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")), @OA\RequestBody(required=true, @OA\JsonContent(@OA\Property(property="values", type="object"))), @OA\Response(response=200, description="Сохранено")) */
    public function update(Request $request, int $id): JsonResponse
    {
        $page = Page::findOrFail($id);
        $data = $request->validate(['values' => 'required|array']);

        foreach ($data['values'] as $attributeId => $value) {
            PageAttributeValue::updateOrCreate(
                ['page_id' => $page->id, 'attribute_id' => $attributeId],
                ['value' => $value]
            );
        }

        return $this->success(null, 'Значения атрибутов сохранены.');
    }
}
