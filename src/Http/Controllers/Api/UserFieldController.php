<?php

namespace Templite\Cms\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Templite\Cms\Http\Requests\UserField\StoreUserFieldRequest;
use Templite\Cms\Http\Requests\UserField\UpdateUserFieldRequest;
use Templite\Cms\Http\Resources\UserFieldResource;
use Templite\Cms\Models\UserField;
use Templite\Cms\Models\UserType;

class UserFieldController extends Controller
{
    /** @OA\Get(path="/user-types/{typeId}/fields", summary="Поля типа пользователя (дерево)", tags={"User Fields"}, security={{"bearerAuth":{}}}, @OA\Parameter(name="typeId", in="path", required=true, @OA\Schema(type="integer")), @OA\Response(response=200, description="Дерево полей")) */
    public function index(int $typeId): JsonResponse
    {
        $type = UserType::findOrFail($typeId);
        $fields = $type->rootFields()->with('children.children')->get();

        return $this->success(UserFieldResource::collection($fields));
    }

    /** @OA\Post(path="/user-types/{typeId}/fields", summary="Создать поле типа пользователя", tags={"User Fields"}, security={{"bearerAuth":{}}}, @OA\Parameter(name="typeId", in="path", required=true, @OA\Schema(type="integer")), @OA\RequestBody(required=true, @OA\JsonContent(required={"name","key","type"}, @OA\Property(property="name", type="string"), @OA\Property(property="key", type="string"), @OA\Property(property="type", type="string"))), @OA\Response(response=201, description="Поле создано")) */
    public function store(StoreUserFieldRequest $request, int $typeId): JsonResponse
    {
        UserType::findOrFail($typeId);

        $data = $request->validated();
        $data['user_type_id'] = $typeId;

        // Auto-order: следующий порядковый номер
        if (!isset($data['order'])) {
            $data['order'] = UserField::where('user_type_id', $typeId)
                ->where('parent_id', $data['parent_id'] ?? null)
                ->max('order') + 1;
        }

        $field = UserField::create($data);

        $this->logAction('create', 'user_field', $field->id, ['name' => $field->name, 'key' => $field->key]);

        return $this->success(
            new UserFieldResource($field->load('children')),
            'Поле создано.',
            201
        );
    }

    /** @OA\Put(path="/user-fields/{id}", summary="Обновить поле типа пользователя", tags={"User Fields"}, security={{"bearerAuth":{}}}, @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")), @OA\Response(response=200, description="Поле обновлено")) */
    public function update(UpdateUserFieldRequest $request, int $id): JsonResponse
    {
        $field = UserField::findOrFail($id);
        $field->update($request->validated());

        $this->logAction('update', 'user_field', $field->id, ['name' => $field->name, 'key' => $field->key]);

        return $this->success(
            new UserFieldResource($field->fresh(['children', 'children.children']))
        );
    }

    /** @OA\Delete(path="/user-fields/{id}", summary="Удалить поле типа пользователя", tags={"User Fields"}, security={{"bearerAuth":{}}}, @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")), @OA\Response(response=200, description="Поле удалено (каскадно с дочерними)")) */
    public function destroy(int $id): JsonResponse
    {
        $field = UserField::findOrFail($id);
        $name = $field->name;

        // Каскадное удаление дочерних полей
        $field->children()->each(function (UserField $child) {
            $child->children()->delete();
            $child->delete();
        });
        $field->delete();

        $this->logAction('delete', 'user_field', $id, ['name' => $name]);

        return $this->success(null, 'Поле удалено.');
    }

    /** @OA\Put(path="/user-types/{typeId}/fields/reorder", summary="Изменить порядок полей", tags={"User Fields"}, security={{"bearerAuth":{}}}, @OA\Parameter(name="typeId", in="path", required=true, @OA\Schema(type="integer")), @OA\RequestBody(required=true, @OA\JsonContent(required={"items"}, @OA\Property(property="items", type="array", @OA\Items(@OA\Property(property="id", type="integer"), @OA\Property(property="order", type="integer"), @OA\Property(property="tab", type="string", nullable=true))))), @OA\Response(response=200, description="Порядок обновлён")) */
    public function reorder(Request $request, int $typeId): JsonResponse
    {
        UserType::findOrFail($typeId);

        $data = $request->validate([
            'items' => ['required', 'array'],
            'items.*.id' => ['required', 'integer', 'exists:cms_user_fields,id'],
            'items.*.order' => ['required', 'integer'],
            'items.*.tab' => ['nullable', 'string'],
        ]);

        foreach ($data['items'] as $item) {
            UserField::where('id', $item['id'])
                ->where('user_type_id', $typeId)
                ->update([
                    'order' => $item['order'],
                    'tab' => $item['tab'] ?? null,
                ]);
        }

        $this->logAction('reorder', 'user_field', null, ['user_type_id' => $typeId]);

        return $this->success(null, 'Порядок обновлён.');
    }
}
