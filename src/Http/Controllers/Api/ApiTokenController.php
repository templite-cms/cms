<?php

namespace Templite\Cms\Http\Controllers\Api;

use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ApiTokenController extends Controller
{
    /**
     * Полный список допустимых abilities для API-токенов.
     */
    protected const ALLOWED_ABILITIES = [
        'blocks:read', 'blocks:write',
        'pages:read', 'pages:write',
        'templates:read', 'templates:write',
        'components:read', 'components:write',
        'settings:read', 'settings:write',
        'files:read', 'files:write',
        'managers:read', 'managers:write',
    ];

    /**
     * Abilities по умолчанию (read-only).
     */
    protected const DEFAULT_ABILITIES = [
        'blocks:read',
        'pages:read',
        'templates:read',
        'components:read',
        'settings:read',
        'files:read',
        'managers:read',
    ];

    /**
     * @OA\Get(
     *     path="/api-tokens",
     *     summary="Список API-токенов текущего менеджера",
     *     tags={"Auth"},
     *     security={{"bearerAuth":{}}},
     *     @OA\Response(response=200, description="Список токенов", @OA\JsonContent(
     *         @OA\Property(property="success", type="boolean", example=true),
     *         @OA\Property(property="message", type="string", example="OK"),
     *         @OA\Property(property="data", type="array", @OA\Items(
     *             @OA\Property(property="id", type="integer", example=1),
     *             @OA\Property(property="name", type="string", example="My API Token"),
     *             @OA\Property(property="abilities", type="array", @OA\Items(type="string", example="*")),
     *             @OA\Property(property="last_used_at", type="string", format="date-time", nullable=true),
     *             @OA\Property(property="expires_at", type="string", format="date-time", nullable=true),
     *             @OA\Property(property="created_at", type="string", format="date-time")
     *         ))
     *     )),
     *     @OA\Response(response=401, description="Не авторизован")
     * )
     */
    public function index(): JsonResponse
    {
        $manager = auth()->user();

        $tokens = $manager->tokens()
            ->orderByDesc('created_at')
            ->get()
            ->map(fn ($token) => [
                'id' => $token->id,
                'name' => $token->name,
                'abilities' => $token->abilities,
                'last_used_at' => $token->last_used_at,
                'expires_at' => $token->expires_at,
                'created_at' => $token->created_at,
            ]);

        return $this->success($tokens);
    }

    /**
     * @OA\Post(
     *     path="/api-tokens",
     *     summary="Создать новый API-токен",
     *     tags={"Auth"},
     *     security={{"bearerAuth":{}}},
     *     @OA\RequestBody(required=true, @OA\JsonContent(
     *         required={"name"},
     *         @OA\Property(property="name", type="string", example="My API Token"),
     *         @OA\Property(property="abilities", type="array", @OA\Items(type="string", example="blocks:read"),
     *             description="Список разрешений. По умолчанию — только чтение. Допустимые: blocks:read, blocks:write, pages:read, pages:write, templates:read, templates:write, components:read, components:write, settings:read, settings:write, files:read, files:write, managers:read, managers:write"
     *         ),
     *         @OA\Property(property="expires_at", type="string", format="date-time", nullable=true, example="2027-01-01T00:00:00Z")
     *     )),
     *     @OA\Response(response=201, description="Токен создан", @OA\JsonContent(
     *         @OA\Property(property="success", type="boolean", example=true),
     *         @OA\Property(property="message", type="string", example="Токен создан. Скопируйте его — он будет показан только один раз."),
     *         @OA\Property(property="data", type="object",
     *             @OA\Property(property="token", type="string", example="1|abc123def456..."),
     *             @OA\Property(property="abilities", type="array", @OA\Items(type="string"))
     *         )
     *     )),
     *     @OA\Response(response=401, description="Не авторизован"),
     *     @OA\Response(response=422, description="Ошибка валидации")
     * )
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'abilities' => 'nullable|array',
            'abilities.*' => 'string|in:*,' . implode(',', self::ALLOWED_ABILITIES),
            'expires_at' => 'nullable|date|after:now',
        ]);

        $manager = auth()->user();

        // По умолчанию — read-only abilities
        if (!empty($data['abilities']) && in_array('*', $data['abilities'])) {
            $abilities = ['*'];
        } elseif (!empty($data['abilities'])) {
            $abilities = array_values(array_intersect($data['abilities'], self::ALLOWED_ABILITIES));
        } else {
            $abilities = self::DEFAULT_ABILITIES;
        }

        $expiresAt = isset($data['expires_at'])
            ? Carbon::parse($data['expires_at'])
            : null;

        $token = $manager->createToken(
            $data['name'],
            $abilities,
            $expiresAt
        );

        $this->logAction('create', 'api_token', $token->accessToken->id, [
            'name' => $data['name'],
            'abilities' => $abilities,
        ]);

        return $this->success(
            [
                'token' => $token->plainTextToken,
                'abilities' => $abilities,
            ],
            'Токен создан. Скопируйте его — он будет показан только один раз.',
            201
        );
    }

    /**
     * @OA\Delete(
     *     path="/api-tokens/{id}",
     *     summary="Отозвать API-токен",
     *     tags={"Auth"},
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         required=true,
     *         description="ID токена",
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\Response(response=200, description="Токен отозван", @OA\JsonContent(
     *         @OA\Property(property="success", type="boolean", example=true),
     *         @OA\Property(property="message", type="string", example="Токен отозван."),
     *         @OA\Property(property="data", type="object", nullable=true)
     *     )),
     *     @OA\Response(response=401, description="Не авторизован"),
     *     @OA\Response(response=404, description="Токен не найден")
     * )
     */
    public function destroy(int $id): JsonResponse
    {
        $manager = auth()->user();

        $deleted = $manager->tokens()->where('id', $id)->delete();

        if ($deleted === 0) {
            return $this->error('Токен не найден.', 404);
        }

        $this->logAction('delete', 'api_token', $id);

        return $this->success(null, 'Токен отозван.');
    }
}
