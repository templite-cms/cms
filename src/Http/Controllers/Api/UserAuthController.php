<?php

namespace Templite\Cms\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Templite\Cms\Http\Resources\UserResource;
use Templite\Cms\Models\User;
use Templite\Cms\Models\UserType;

class UserAuthController extends Controller
{
    /** @OA\Post(path="/user-auth/{guard}/login", summary="Авторизация пользователя сайта", tags={"User Auth"}, @OA\Parameter(name="guard", in="path", required=true, @OA\Schema(type="string")), @OA\RequestBody(required=true, @OA\JsonContent(required={"email","password"}, @OA\Property(property="email", type="string"), @OA\Property(property="password", type="string"))), @OA\Response(response=200, description="Успешный вход")) */
    public function login(Request $request, string $guard): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $userType = UserType::where('guard', $guard)->where('is_active', true)->first();

        if (!$userType) {
            return $this->error('Тип пользователя не найден или неактивен.', 404);
        }

        $user = User::where('email', $data['email'])
            ->where('user_type_id', $userType->id)
            ->first();

        if (!$user || !Hash::check($data['password'], $user->password)) {
            return $this->error('Неверный email или пароль.', 401);
        }

        if (!$user->is_active) {
            return $this->error('Аккаунт деактивирован.', 403);
        }

        Auth::guard($guard)->login($user);
        $request->session()->regenerate();

        return $this->success(
            new UserResource($user->load(['userType', 'avatar'])),
            'Вход выполнен.'
        );
    }

    /** @OA\Post(path="/user-auth/{guard}/register", summary="Регистрация пользователя сайта", tags={"User Auth"}, @OA\Parameter(name="guard", in="path", required=true, @OA\Schema(type="string")), @OA\RequestBody(required=true, @OA\JsonContent(required={"name","email","password","password_confirmation"}, @OA\Property(property="name", type="string"), @OA\Property(property="email", type="string"), @OA\Property(property="password", type="string"), @OA\Property(property="password_confirmation", type="string"))), @OA\Response(response=201, description="Регистрация успешна")) */
    public function register(Request $request, string $guard): JsonResponse
    {
        $userType = UserType::where('guard', $guard)->where('is_active', true)->first();

        if (!$userType) {
            return $this->error('Тип пользователя не найден или неактивен.', 404);
        }

        if (!$userType->isRegistrationEnabled()) {
            return $this->error('Регистрация для этого типа пользователей отключена.', 403);
        }

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => [
                'required',
                'email',
                'max:255',
                Rule::unique('cms_users', 'email')
                    ->where('user_type_id', $userType->id),
            ],
            'password' => ['required', 'string', 'min:6', 'confirmed'],
        ]);

        $user = User::create([
            'user_type_id' => $userType->id,
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => Hash::make($data['password']),
            'is_active' => true,
        ]);

        Auth::guard($guard)->login($user);
        $request->session()->regenerate();

        return $this->success(
            new UserResource($user->load(['userType', 'avatar'])),
            'Регистрация успешна.',
            201
        );
    }

    /** @OA\Post(path="/user-auth/{guard}/logout", summary="Выход пользователя сайта", tags={"User Auth"}, @OA\Parameter(name="guard", in="path", required=true, @OA\Schema(type="string")), @OA\Response(response=200, description="Выход выполнен")) */
    public function logout(Request $request, string $guard): JsonResponse
    {
        Auth::guard($guard)->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return $this->success(null, 'Выход выполнен.');
    }

    /** @OA\Get(path="/user-auth/{guard}/me", summary="Текущий авторизованный пользователь", tags={"User Auth"}, @OA\Parameter(name="guard", in="path", required=true, @OA\Schema(type="string")), @OA\Response(response=200, description="Данные пользователя")) */
    public function me(string $guard): JsonResponse
    {
        $user = Auth::guard($guard)->user();

        if (!$user) {
            return $this->error('Не авторизован.', 401);
        }

        $user->load(['userType.rootFields.children', 'avatar']);

        return $this->success(new UserResource($user));
    }
}
