<?php

namespace Templite\Cms\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Templite\Cms\Http\Resources\BlockTypeResource;
use Templite\Cms\Models\BlockType;

class BlockTypeController extends Controller
{
    /** @OA\Get(path="/block-types", summary="Список типов блоков", tags={"Block Types"}, security={{"bearerAuth":{}}}, @OA\Response(response=200, description="Список")) */
    public function index(): JsonResponse
    {
        $types = BlockType::withCount('blocks')->orderBy('order')->get();
        return $this->success(BlockTypeResource::collection($types));
    }

    /** @OA\Post(path="/block-types", summary="Создать тип блока", tags={"Block Types"}, security={{"bearerAuth":{}}}, @OA\RequestBody(required=true, @OA\JsonContent(required={"name","slug"}, @OA\Property(property="name", type="string"), @OA\Property(property="slug", type="string"), @OA\Property(property="description", type="string"), @OA\Property(property="type", type="integer"))), @OA\Response(response=201, description="Создано")) */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => 'required|string|max:255', 'slug' => 'required|string|max:255|unique:block_types',
            'description' => 'nullable|string', 'type' => 'integer|in:0,1,2', 'order' => 'integer',
        ]);
        $type = BlockType::create($data);

        $this->logAction('create', 'block_type', $type->id, ['name' => $type->name, 'slug' => $type->slug]);

        return $this->success(new BlockTypeResource($type), 'Тип блока создан.', 201);
    }

    /** @OA\Put(path="/block-types/{id}", summary="Обновить тип блока", tags={"Block Types"}, security={{"bearerAuth":{}}}, @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")), @OA\Response(response=200, description="Обновлено")) */
    public function update(Request $request, int $id): JsonResponse
    {
        $type = BlockType::findOrFail($id);
        $data = $request->validate([
            'name' => 'sometimes|string|max:255', 'slug' => 'sometimes|string|max:255|unique:block_types,slug,' . $id,
            'description' => 'nullable|string', 'type' => 'integer|in:0,1,2', 'order' => 'integer',
        ]);
        $type->update($data);

        $this->logAction('update', 'block_type', $type->id, ['name' => $type->name]);

        return $this->success(new BlockTypeResource($type->fresh()));
    }

    /** @OA\Delete(path="/block-types/{id}", summary="Удалить тип блока", tags={"Block Types"}, security={{"bearerAuth":{}}}, @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")), @OA\Response(response=200, description="Удалено")) */
    public function destroy(int $id): JsonResponse
    {
        $type = BlockType::findOrFail($id);
        $name = $type->name;
        $type->delete();

        $this->logAction('delete', 'block_type', $id, ['name' => $name]);

        return $this->success(null, 'Тип блока удалён.');
    }
}
