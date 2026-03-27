<?php

namespace Templite\Cms\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Templite\Cms\Helpers\StringHelper;
use Templite\Cms\Http\Requests\User\StoreUserRequest;
use Templite\Cms\Http\Requests\User\UpdateUserRequest;
use Templite\Cms\Http\Resources\UserResource;
use Templite\Cms\Models\User;
use Templite\Cms\Services\UserDataResolver;

class UserController extends Controller
{
    public function __construct(
        protected UserDataResolver $userDataResolver
    ) {}

    /** @OA\Get(path="/users", summary="Список пользователей", tags={"Users"}, security={{"bearerAuth":{}}}, @OA\Parameter(name="user_type_id", in="query", @OA\Schema(type="integer")), @OA\Parameter(name="search", in="query", @OA\Schema(type="string")), @OA\Parameter(name="per_page", in="query", @OA\Schema(type="integer")), @OA\Response(response=200, description="Список")) */
    public function index(Request $request): JsonResponse
    {
        $query = User::with(['userType', 'avatar'])->orderByDesc('created_at');

        if ($typeId = $request->input('user_type_id')) {
            $query->where('user_type_id', (int) $typeId);
        }

        if ($search = $request->input('search')) {
            $escaped = StringHelper::escapeLike($search);
            $query->where(function ($q) use ($escaped) {
                $q->where('name', 'like', '%' . $escaped . '%')
                  ->orWhere('email', 'like', '%' . $escaped . '%');
            });
        }

        $perPage = min((int) $request->input('per_page', 25), 100);
        $users = $query->paginate($perPage);

        return $this->success([
            'data' => UserResource::collection($users),
            'meta' => [
                'current_page' => $users->currentPage(),
                'last_page' => $users->lastPage(),
                'per_page' => $users->perPage(),
                'total' => $users->total(),
            ],
        ]);
    }

    /** @OA\Get(path="/users/{id}", summary="Получить пользователя", tags={"Users"}, security={{"bearerAuth":{}}}, @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")), @OA\Response(response=200, description="Данные")) */
    public function show(int $id): JsonResponse
    {
        $user = User::with(['userType.rootFields.children', 'avatar'])->findOrFail($id);
        $user->resolved_data = $this->userDataResolver->resolve($user);

        return $this->success(new UserResource($user));
    }

    /** @OA\Post(path="/users", summary="Создать пользователя", tags={"Users"}, security={{"bearerAuth":{}}}, @OA\RequestBody(required=true, @OA\JsonContent(required={"user_type_id","name","email","password"}, @OA\Property(property="user_type_id", type="integer"), @OA\Property(property="name", type="string"), @OA\Property(property="email", type="string"), @OA\Property(property="password", type="string"))), @OA\Response(response=201, description="Создано")) */
    public function store(StoreUserRequest $request): JsonResponse
    {
        $data = $request->validated();
        $data['password'] = Hash::make($data['password']);

        $user = User::create($data);

        $this->logAction('create', 'user', $user->id, ['name' => $user->name, 'email' => $user->email]);

        return $this->success(
            new UserResource($user->load(['userType', 'avatar'])),
            'Пользователь создан.',
            201
        );
    }

    /** @OA\Put(path="/users/{id}", summary="Обновить пользователя", tags={"Users"}, security={{"bearerAuth":{}}}, @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")), @OA\Response(response=200, description="Обновлено")) */
    public function update(UpdateUserRequest $request, int $id): JsonResponse
    {
        $user = User::findOrFail($id);
        $data = $request->validated();

        // Пропускаем пустой пароль
        if (empty($data['password'])) {
            unset($data['password']);
        } else {
            $data['password'] = Hash::make($data['password']);
        }

        $user->update($data);

        $this->logAction('update', 'user', $user->id, ['name' => $user->name, 'email' => $user->email]);

        return $this->success(new UserResource($user->fresh(['userType', 'avatar'])));
    }

    /** @OA\Delete(path="/users/{id}", summary="Удалить пользователя", tags={"Users"}, security={{"bearerAuth":{}}}, @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")), @OA\Response(response=200, description="Удалено")) */
    public function destroy(int $id): JsonResponse
    {
        $user = User::findOrFail($id);
        $name = $user->name;
        $email = $user->email;
        $user->delete();

        $this->logAction('delete', 'user', $id, ['name' => $name, 'email' => $email]);

        return $this->success(null, 'Пользователь удалён.');
    }

    /** @OA\Put(path="/users/{id}/toggle-active", summary="Переключить активность пользователя", tags={"Users"}, security={{"bearerAuth":{}}}, @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")), @OA\Response(response=200, description="Статус изменён")) */
    public function toggleActive(int $id): JsonResponse
    {
        $user = User::findOrFail($id);
        $user->is_active = !$user->is_active;
        $user->save();

        $this->logAction('toggle_active', 'user', $user->id, [
            'name' => $user->name,
            'is_active' => $user->is_active,
        ]);

        return $this->success(
            new UserResource($user->load(['userType', 'avatar'])),
            $user->is_active ? 'Пользователь активирован.' : 'Пользователь деактивирован.'
        );
    }
}
