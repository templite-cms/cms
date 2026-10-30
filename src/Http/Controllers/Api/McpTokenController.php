<?php

namespace Templite\Cms\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;
use Templite\Cms\Models\Manager;
use Templite\Cms\Models\ManagerType;
use Templite\Cms\Services\Mcp\McpScope;

/**
 * Личные MCP-токены менеджера. Токен не даёт больше прав, чем у владельца:
 * инструменты проверяют права менеджера, abilities и профиль только сужают.
 */
class McpTokenController extends Controller
{
    private const CODE_PERMISSIONS = ['blocks.code', 'actions.code', 'templates.code', 'components.code'];

    /**
     * Свои токены; администратору — токены всех менеджеров, чтобы он мог
     * отозвать чужой (например, у уволенного сотрудника).
     */
    public function index(Request $request): JsonResponse
    {
        $me = $request->user();

        $tokens = $this->visibleTokens($me)
            ->with('tokenable')
            ->orderByDesc('created_at')
            ->get()
            ->filter(fn (PersonalAccessToken $t) => McpScope::isMcpToken($t))
            ->map(function (PersonalAccessToken $t) use ($me) {
                $scope = McpScope::fromToken($t);
                $own = $t->tokenable_id === $me->id && $t->tokenable instanceof Manager;

                return [
                    'id' => $t->id,
                    'name' => $t->name,
                    'profile' => $scope->profile,
                    'abilities' => $scope->abilities,
                    'own' => $own,
                    'owner' => $own ? null : ($t->tokenable?->name ?? $t->tokenable?->login),
                    'last_used_at' => $t->last_used_at,
                    'expires_at' => $t->expires_at,
                    'created_at' => $t->created_at,
                ];
            })
            ->values();

        return $this->success($tokens);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate($this->rules(expiresRequired: true));

        $manager = $request->user();
        $abilities = $this->abilities($data['abilities'], $manager);

        $token = $manager->createToken(
            $data['name'],
            $this->sanctumAbilities($abilities, $data['profile']),
            now()->addDays((int) $data['expires_days']),
        );

        $this->logAction('create', 'mcp_token', $token->accessToken->id, [
            'name' => $data['name'],
            'profile' => $data['profile'],
            'abilities' => $abilities,
        ]);

        return $this->success(
            ['id' => $token->accessToken->id, 'token' => $token->plainTextToken],
            'Токен создан. Скопируйте его — он будет показан только один раз.',
            201,
        );
    }

    /**
     * Изменить название, профиль, права и срок. Секрет токена не меняется:
     * клиентам, где он прописан, перенастраиваться не нужно.
     */
    public function update(Request $request, int $id): JsonResponse
    {
        // expires_days не передан — срок остаётся прежним
        $data = $request->validate($this->rules(expiresRequired: false));

        $token = $request->user()->tokens()->whereKey($id)->first();

        if (! $token || ! McpScope::isMcpToken($token)) {
            return $this->error('Токен не найден.', 404);
        }

        $abilities = $this->abilities($data['abilities'], $request->user());

        $token->forceFill(array_filter([
            'name' => $data['name'],
            'abilities' => $this->sanctumAbilities($abilities, $data['profile']),
            'expires_at' => isset($data['expires_days']) ? now()->addDays((int) $data['expires_days']) : null,
        ], fn ($v) => $v !== null))->save();

        $this->logAction('update', 'mcp_token', $token->id, [
            'name' => $data['name'],
            'profile' => $data['profile'],
            'abilities' => $abilities,
        ]);

        return $this->success(null, 'Токен обновлён');
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $token = $this->visibleTokens($request->user())->whereKey($id)->first();

        if (! $token || ! McpScope::isMcpToken($token)) {
            return $this->error('Токен не найден.', 404);
        }

        $token->delete();
        $this->logAction('delete', 'mcp_token', $id, ['owner_id' => $token->tokenable_id]);

        return $this->success(null, 'Токен отозван');
    }

    /**
     * Менять чужой токен нельзя: abilities сверяются с правами того, кто
     * правит, а работать токен будет с правами владельца. Отозвать — можно.
     */
    private function visibleTokens(Manager $me): \Illuminate\Database\Eloquent\Builder|\Illuminate\Database\Eloquent\Relations\MorphMany
    {
        return $me->isAdmin()
            ? PersonalAccessToken::query()->where('tokenable_type', $me->getMorphClass())
            : $me->tokens();
    }

    /** @return array<string,mixed> */
    private function rules(bool $expiresRequired): array
    {
        return [
            'name' => 'required|string|max:255',
            'profile' => ['required', Rule::in(McpScope::PROFILES)],
            // present, а не required: токен «только чтение» приходит с пустым списком
            'abilities' => 'present|array',
            'abilities.*' => [Rule::in(McpScope::ABILITIES)],
            'expires_days' => [$expiresRequired ? 'required' : 'nullable', 'integer', Rule::in([7, 30, 60, 90, 365])],
        ];
    }

    /**
     * Чтение есть всегда; остальное — только если менеджер сам может хоть
     * что-то из этого делать. Точечные права всё равно проверяют инструменты.
     *
     * @param  array<int,string>  $requested
     * @return array<int,string>
     */
    private function abilities(array $requested, mixed $manager): array
    {
        $abilities = array_values(array_unique(['read', ...$requested]));
        $catalog = ManagerType::getAvailablePermissions();

        $required = [
            'write' => ['Запись', preg_grep('/\.(create|edit)$/', $catalog)],
            'code' => ['Код', self::CODE_PERMISSIONS],
            'delete' => ['Удаление', preg_grep('/\.delete$/', $catalog)],
        ];

        foreach ($required as $ability => [$label, $permissions]) {
            if (in_array($ability, $abilities, true)
                && ! collect($permissions)->contains(fn ($p) => $manager->hasPermission($p))) {
                throw ValidationException::withMessages([
                    'abilities' => "У вас нет подходящих прав — ability «{$label}» выдать нельзя.",
                ]);
            }
        }

        return $abilities;
    }

    /**
     * @param  array<int,string>  $abilities
     * @return array<int,string>
     */
    private function sanctumAbilities(array $abilities, string $profile): array
    {
        return [...array_map(fn ($a) => 'mcp:'.$a, $abilities), 'mcp-profile:'.$profile];
    }
}
