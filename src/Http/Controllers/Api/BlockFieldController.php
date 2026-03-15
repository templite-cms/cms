<?php

namespace Templite\Cms\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Templite\Cms\Http\Requests\BlockField\ReorderBlockFieldsRequest;
use Templite\Cms\Http\Requests\BlockField\StoreBlockFieldRequest;
use Templite\Cms\Http\Requests\BlockField\UpdateBlockFieldRequest;
use Templite\Cms\Http\Resources\BlockFieldResource;
use Templite\Cms\Models\Block;
use Templite\Cms\Models\BlockField;
use Templite\Cms\Services\FieldableService;

class BlockFieldController extends Controller
{
    public function __construct(
        protected FieldableService $fieldableService
    ) {}

    /**
     * @OA\Get(
     *     path="/blocks/{blockId}/fields",
     *     summary="Поля блока (дерево)",
     *     tags={"Block Fields"},
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(name="blockId", in="path", required=true, @OA\Schema(type="integer")),
     *     @OA\Response(response=200, description="Дерево полей блока"),
     *     @OA\Response(response=404, description="Блок не найден")
     * )
     */
    public function index(int $blockId): JsonResponse
    {
        $block = Block::findOrFail($blockId);
        $fields = $this->fieldableService->getFieldsTree($block);

        return $this->success(BlockFieldResource::collection($fields));
    }

    /**
     * @OA\Post(
     *     path="/blocks/{blockId}/fields",
     *     summary="Создать поле блока",
     *     tags={"Block Fields"},
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(name="blockId", in="path", required=true, @OA\Schema(type="integer")),
     *     @OA\RequestBody(required=true, @OA\JsonContent(
     *         required={"name", "key", "type"},
     *         @OA\Property(property="name", type="string", example="Заголовок"),
     *         @OA\Property(property="key", type="string", example="title"),
     *         @OA\Property(property="type", type="string", example="text"),
     *         @OA\Property(property="parent_id", type="integer", nullable=true),
     *         @OA\Property(property="default_value", type="string", nullable=true),
     *         @OA\Property(property="data", type="object", nullable=true),
     *         @OA\Property(property="hint", type="string", nullable=true),
     *         @OA\Property(property="block_tab_id", type="integer", nullable=true),
     *         @OA\Property(property="block_section_id", type="integer", nullable=true),
     *         @OA\Property(property="order", type="integer")
     *     )),
     *     @OA\Response(response=201, description="Поле создано"),
     *     @OA\Response(response=404, description="Блок не найден"),
     *     @OA\Response(response=422, description="Ошибка валидации")
     * )
     */
    public function store(StoreBlockFieldRequest $request, int $blockId): JsonResponse
    {
        $block = Block::findOrFail($blockId);
        $field = $this->fieldableService->createField($block, $request->validated());

        $this->logAction('create', 'block_field', $field->id, ['name' => $field->name, 'key' => $field->key]);

        return $this->success(
            new BlockFieldResource($field->load('children')),
            'Поле создано.',
            201
        );
    }

    /**
     * @OA\Put(
     *     path="/block-fields/{id}",
     *     summary="Обновить поле блока",
     *     tags={"Block Fields"},
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
     *     @OA\RequestBody(required=true, @OA\JsonContent(
     *         @OA\Property(property="name", type="string"),
     *         @OA\Property(property="key", type="string"),
     *         @OA\Property(property="type", type="string"),
     *         @OA\Property(property="default_value", type="string", nullable=true),
     *         @OA\Property(property="data", type="object", nullable=true),
     *         @OA\Property(property="hint", type="string", nullable=true),
     *         @OA\Property(property="block_tab_id", type="integer", nullable=true),
     *         @OA\Property(property="block_section_id", type="integer", nullable=true),
     *         @OA\Property(property="order", type="integer")
     *     )),
     *     @OA\Response(response=200, description="Поле обновлено"),
     *     @OA\Response(response=404, description="Поле не найдено"),
     *     @OA\Response(response=422, description="Ошибка валидации")
     * )
     */
    public function update(UpdateBlockFieldRequest $request, int $id): JsonResponse
    {
        $field = BlockField::findOrFail($id);
        $field->update($request->validated());

        $this->logAction('update', 'block_field', $field->id, ['name' => $field->name, 'key' => $field->key]);

        return $this->success(
            new BlockFieldResource($field->fresh(['children', 'children.children']))
        );
    }

    /**
     * @OA\Delete(
     *     path="/block-fields/{id}",
     *     summary="Удалить поле блока",
     *     tags={"Block Fields"},
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
     *     @OA\Response(response=200, description="Поле удалено (каскадно с дочерними)"),
     *     @OA\Response(response=404, description="Поле не найдено")
     * )
     */
    public function destroy(int $id): JsonResponse
    {
        $field = BlockField::findOrFail($id);
        $name = $field->name;
        $field->children()->each(function (BlockField $child) {
            $child->children()->delete();
            $child->delete();
        });
        $field->delete();

        $this->logAction('delete', 'block_field', $id, ['name' => $name]);

        return $this->success(null, 'Поле удалено.');
    }

    /**
     * @OA\Put(
     *     path="/blocks/{blockId}/fields/reorder",
     *     summary="Изменить порядок полей блока",
     *     tags={"Block Fields"},
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(name="blockId", in="path", required=true, @OA\Schema(type="integer")),
     *     @OA\RequestBody(required=true, @OA\JsonContent(
     *         required={"items"},
     *         @OA\Property(property="items", type="array", @OA\Items(
     *             @OA\Property(property="id", type="integer"),
     *             @OA\Property(property="order", type="integer"),
     *             @OA\Property(property="block_section_id", type="integer", nullable=true),
     *             @OA\Property(property="block_tab_id", type="integer", nullable=true)
     *         ))
     *     )),
     *     @OA\Response(response=200, description="Порядок обновлен"),
     *     @OA\Response(response=404, description="Блок не найден"),
     *     @OA\Response(response=422, description="Ошибка валидации")
     * )
     */
    public function reorder(ReorderBlockFieldsRequest $request, int $blockId): JsonResponse
    {
        $block = Block::findOrFail($blockId);
        $this->fieldableService->reorderFields($block, $request->validated()['items']);

        $this->logAction('reorder', 'block_field', null, ['block_id' => $blockId]);

        return $this->success(null, 'Порядок обновлен.');
    }
}
