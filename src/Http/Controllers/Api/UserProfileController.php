<?php

namespace Templite\Cms\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Templite\Cms\Http\Resources\UserResource;
use Templite\Cms\Services\UserDataResolver;

class UserProfileController extends Controller
{
    public function __construct(
        protected UserDataResolver $userDataResolver
    ) {}

    /** @OA\Get(path="/user-profile/{guard}", summary="Профиль текущего пользователя", tags={"User Profile"}, @OA\Parameter(name="guard", in="path", required=true, @OA\Schema(type="string")), @OA\Response(response=200, description="Данные профиля")) */
    public function show(string $guard): JsonResponse
    {
        $user = Auth::guard($guard)->user();

        if (!$user) {
            return $this->error('Не авторизован.', 401);
        }

        $user->load(['userType.rootFields.children', 'avatar']);
        $user->resolved_data = $this->userDataResolver->resolve($user);

        return $this->success(new UserResource($user));
    }

    /** @OA\Put(path="/user-profile/{guard}", summary="Обновить профиль", tags={"User Profile"}, @OA\Parameter(name="guard", in="path", required=true, @OA\Schema(type="string")), @OA\RequestBody(required=true, @OA\JsonContent(@OA\Property(property="name", type="string"), @OA\Property(property="email", type="string"), @OA\Property(property="avatar_id", type="integer", nullable=true), @OA\Property(property="data", type="object", nullable=true))), @OA\Response(response=200, description="Профиль обновлён")) */
    public function update(Request $request, string $guard): JsonResponse
    {
        $user = Auth::guard($guard)->user();

        if (!$user) {
            return $this->error('Не авторизован.', 401);
        }

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'email' => [
                'sometimes',
                'email',
                'max:255',
                Rule::unique('cms_users', 'email')
                    ->where('user_type_id', $user->user_type_id)
                    ->ignore($user->id),
            ],
            'avatar_id' => ['nullable', 'integer', 'exists:files,id'],
            'data' => ['nullable', 'array'],
        ]);

        $user->update($data);

        return $this->success(
            new UserResource($user->fresh(['userType', 'avatar'])),
            'Профиль обновлён.'
        );
    }

    /** @OA\Put(path="/user-profile/{guard}/password", summary="Сменить пароль", tags={"User Profile"}, @OA\Parameter(name="guard", in="path", required=true, @OA\Schema(type="string")), @OA\RequestBody(required=true, @OA\JsonContent(required={"current_password","password","password_confirmation"}, @OA\Property(property="current_password", type="string"), @OA\Property(property="password", type="string"), @OA\Property(property="password_confirmation", type="string"))), @OA\Response(response=200, description="Пароль изменён")) */
    public function updatePassword(Request $request, string $guard): JsonResponse
    {
        $user = Auth::guard($guard)->user();

        if (!$user) {
            return $this->error('Не авторизован.', 401);
        }

        $data = $request->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'string', 'min:6', 'confirmed'],
        ]);

        if (!Hash::check($data['current_password'], $user->password)) {
            return $this->error('Текущий пароль неверен.', 422);
        }

        $user->update([
            'password' => Hash::make($data['password']),
        ]);

        return $this->success(null, 'Пароль изменён.');
    }
}
