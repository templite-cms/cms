<?php

namespace Templite\Cms\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Templite\Cms\Http\Resources\ManagerTypeResource;
use Templite\Cms\Models\ManagerType;
use Templite\Cms\Services\ModuleRegistry;

class ManagerTypeController extends Controller
{
    /** @OA\Get(path="/manager-types", summary="Список типов менеджеров", tags={"Manager Types"}, security={{"bearerAuth":{}}}, @OA\Response(response=200, description="Список")) */
    public function index(): JsonResponse
    {
        return $this->success(ManagerTypeResource::collection(ManagerType::withCount('managers')->get()));
    }

    /** @OA\Post(path="/manager-types", summary="Создать тип менеджера", tags={"Manager Types"}, security={{"bearerAuth":{}}}, @OA\RequestBody(required=true, @OA\JsonContent(required={"name","slug"}, @OA\Property(property="name", type="string"), @OA\Property(property="slug", type="string"), @OA\Property(property="permissions", type="array", @OA\Items(type="string")))), @OA\Response(response=201, description="Создано")) */
    public function store(Request $request): JsonResponse
    {
        $allowedPermissions = $this->getAllowedPermissionValues();

        $data = $request->validate([
            'name' => 'required|string|max:255', 'slug' => 'required|string|max:255|unique:manager_types',
            'permissions' => 'nullable|array',
            'permissions.*' => ['string', Rule::in($allowedPermissions)],
        ]);
        $type = ManagerType::create($data);

        $this->logAction('create', 'manager_type', $type->id, ['name' => $type->name, 'slug' => $type->slug]);

        return $this->success(new ManagerTypeResource($type), 'Тип менеджера создан.', 201);
    }

    /** @OA\Get(path="/manager-types/{id}", summary="Получить тип менеджера", tags={"Manager Types"}, security={{"bearerAuth":{}}}, @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")), @OA\Response(response=200, description="Данные")) */
    public function show(int $id): JsonResponse
    {
        return $this->success(new ManagerTypeResource(ManagerType::withCount('managers')->findOrFail($id)));
    }

    /** @OA\Put(path="/manager-types/{id}", summary="Обновить тип менеджера", tags={"Manager Types"}, security={{"bearerAuth":{}}}, @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")), @OA\Response(response=200, description="Обновлено")) */
    public function update(Request $request, int $id): JsonResponse
    {
        $type = ManagerType::findOrFail($id);
        $allowedPermissions = $this->getAllowedPermissionValues();

        $data = $request->validate([
            'name' => 'sometimes|string|max:255', 'slug' => 'sometimes|string|max:255|unique:manager_types,slug,' . $id,
            'permissions' => 'nullable|array',
            'permissions.*' => ['string', Rule::in($allowedPermissions)],
        ]);
        $type->update($data);

        $this->logAction('update', 'manager_type', $type->id, ['name' => $type->name]);

        return $this->success(new ManagerTypeResource($type->fresh()));
    }

    /**
     * Получить полный список допустимых значений permissions для валидации.
     *
     * @return array Плоский массив допустимых permission-строк, включая '*'
     */
    private function getAllowedPermissionValues(): array
    {
        try {
            $registry = app(ModuleRegistry::class);
            $permissions = $registry->getPermissionKeys();
        } catch (\Throwable) {
            $permissions = [];
        }

        if (empty($permissions)) {
            $permissions = config('cms.permissions', []);
        }

        $permissions[] = '*';

        return array_unique($permissions);
    }

    /** @OA\Delete(path="/manager-types/{id}", summary="Удалить тип менеджера", tags={"Manager Types"}, security={{"bearerAuth":{}}}, @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")), @OA\Response(response=200, description="Удалено")) */
    public function destroy(int $id): JsonResponse
    {
        $type = ManagerType::findOrFail($id);
        $name = $type->name;
        $type->delete();

        $this->logAction('delete', 'manager_type', $id, ['name' => $name]);

        return $this->success(null, 'Тип менеджера удалён.');
    }
}
