<?php

namespace Templite\Cms\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Templite\Cms\Http\Requests\UserType\StoreUserTypeRequest;
use Templite\Cms\Http\Requests\UserType\UpdateUserTypeRequest;
use Templite\Cms\Http\Resources\UserTypeResource;
use Templite\Cms\Models\UserType;

class UserTypeController extends Controller
{
    /** @OA\Get(path="/user-types", summary="Список типов пользователей", tags={"User Types"}, security={{"bearerAuth":{}}}, @OA\Response(response=200, description="Список")) */
    public function index(): JsonResponse
    {
        $types = UserType::withCount('users')
            ->with(['rootFields.children'])
            ->orderBy('name')
            ->get();

        return $this->success(UserTypeResource::collection($types));
    }

    /** @OA\Get(path="/user-types/{id}", summary="Получить тип пользователя", tags={"User Types"}, security={{"bearerAuth":{}}}, @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")), @OA\Response(response=200, description="Данные")) */
    public function show(int $id): JsonResponse
    {
        $type = UserType::withCount('users')
            ->with(['rootFields.children'])
            ->findOrFail($id);

        return $this->success(new UserTypeResource($type));
    }

    /** @OA\Post(path="/user-types", summary="Создать тип пользователя", tags={"User Types"}, security={{"bearerAuth":{}}}, @OA\RequestBody(required=true, @OA\JsonContent(required={"name","slug","guard","module"}, @OA\Property(property="name", type="string"), @OA\Property(property="slug", type="string"), @OA\Property(property="guard", type="string"), @OA\Property(property="module", type="string"))), @OA\Response(response=201, description="Создано")) */
    public function store(StoreUserTypeRequest $request): JsonResponse
    {
        $type = UserType::create($request->validated());

        $this->logAction('create', 'user_type', $type->id, ['name' => $type->name, 'slug' => $type->slug]);

        return $this->success(new UserTypeResource($type), 'Тип пользователя создан.', 201);
    }

    /** @OA\Put(path="/user-types/{id}", summary="Обновить тип пользователя", tags={"User Types"}, security={{"bearerAuth":{}}}, @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")), @OA\Response(response=200, description="Обновлено")) */
    public function update(UpdateUserTypeRequest $request, int $id): JsonResponse
    {
        $type = UserType::findOrFail($id);
        $type->update($request->validated());

        $this->logAction('update', 'user_type', $type->id, ['name' => $type->name]);

        return $this->success(new UserTypeResource($type->fresh(['rootFields.children'])->loadCount('users')));
    }

    /** @OA\Delete(path="/user-types/{id}", summary="Удалить тип пользователя", tags={"User Types"}, security={{"bearerAuth":{}}}, @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")), @OA\Response(response=200, description="Удалено")) */
    public function destroy(int $id): JsonResponse
    {
        $type = UserType::withCount('users')->findOrFail($id);

        if ($type->users_count > 0) {
            return $this->error('Невозможно удалить тип с существующими пользователями. Сначала удалите или перенесите пользователей.', 422);
        }

        $name = $type->name;
        $type->fields()->delete();
        $type->delete();

        $this->logAction('delete', 'user_type', $id, ['name' => $name]);

        return $this->success(null, 'Тип пользователя удалён.');
    }
}
