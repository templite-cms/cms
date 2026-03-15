<?php

namespace Templite\Cms\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Templite\Cms\Http\Resources\ManagerResource;
use Templite\Cms\Models\Manager;
use Templite\Cms\Models\ManagerSession;
use Templite\Cms\Services\ModuleRegistry;

class ManagerController extends Controller
{
    /** @OA\Get(path="/managers", summary="Список менеджеров", tags={"Managers"}, security={{"bearerAuth":{}}}, @OA\Response(response=200, description="Список")) */
    public function index(): JsonResponse
    {
        $managers = Manager::with(['managerType', 'avatar'])->get();
        return $this->success(ManagerResource::collection($managers));
    }

    /** @OA\Post(path="/managers", summary="Создать менеджера", tags={"Managers"}, security={{"bearerAuth":{}}}, @OA\RequestBody(required=true, @OA\JsonContent(required={"login","password","type_id"}, @OA\Property(property="login", type="string"), @OA\Property(property="password", type="string"), @OA\Property(property="type_id", type="integer"))), @OA\Response(response=201, description="Создано")) */
    public function store(Request $request): JsonResponse
    {
        $allowedPermissions = $this->getAllowedPermissionValues();

        $data = $request->validate([
            'login' => 'required|string|max:255|unique:managers',
            'password' => ['required', 'string', Password::min(8)->letters()->mixedCase()->numbers()],
            'name' => 'nullable|string|max:255',
            'email' => 'nullable|email|max:255',
            'type_id' => 'required|integer|exists:manager_types,id',
            'settings' => 'nullable|array',
            'personal_permissions' => 'nullable|array',
            'personal_permissions.*' => ['string', Rule::in($allowedPermissions)],
            'use_personal_permissions' => 'boolean',
            'avatar_id' => 'nullable|integer|exists:files,id',
            'is_active' => 'boolean',
        ]);

        // Проверка прав на установку personal_permissions
        if ($error = $this->validatePermissionAssignment($data)) {
            return $error;
        }

        $data['password'] = Hash::make($data['password']);
        $manager = Manager::create($data);

        $this->logAction('create', 'manager', $manager->id, ['login' => $manager->login, 'name' => $manager->name]);

        // Аудит-лог при установке прав
        if (!empty($data['personal_permissions']) || !empty($data['use_personal_permissions'])) {
            $this->logAction('permissions_set', 'manager', $manager->id, [
                'personal_permissions' => $data['personal_permissions'] ?? null,
                'use_personal_permissions' => $data['use_personal_permissions'] ?? false,
                'set_by' => auth()->id(),
            ]);
        }

        return $this->success(new ManagerResource($manager->load(['managerType', 'avatar'])), 'Менеджер создан.', 201);
    }

    /** @OA\Get(path="/managers/{id}", summary="Получить менеджера", tags={"Managers"}, security={{"bearerAuth":{}}}, @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")), @OA\Response(response=200, description="Данные")) */
    public function show(int $id): JsonResponse
    {
        $manager = Manager::with(['managerType', 'avatar'])->findOrFail($id);
        return $this->success(new ManagerResource($manager));
    }

    /** @OA\Put(path="/managers/{id}", summary="Обновить менеджера", tags={"Managers"}, security={{"bearerAuth":{}}}, @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")), @OA\Response(response=200, description="Обновлено")) */
    public function update(Request $request, int $id): JsonResponse
    {
        /** @var Manager $currentManager */
        $currentManager = auth()->user();
        $manager = Manager::findOrFail($id);

        // 1. Запрет редактирования собственных прав
        if ($currentManager->id === $manager->id) {
            if ($request->has('personal_permissions') || $request->has('use_personal_permissions')) {
                $this->logAction('permissions_self_edit_blocked', 'manager', $manager->id, [
                    'attempted_permissions' => $request->input('personal_permissions'),
                    'attempted_use_personal' => $request->input('use_personal_permissions'),
                ]);
                return $this->error('Запрещено редактировать собственные права доступа.', 403);
            }
        }

        $allowedPermissions = $this->getAllowedPermissionValues();

        $data = $request->validate([
            'login' => 'sometimes|string|max:255|unique:managers,login,' . $id,
            'password' => ['sometimes', 'string', Password::min(8)->letters()->mixedCase()->numbers()],
            'name' => 'nullable|string|max:255',
            'email' => 'nullable|email|max:255',
            'type_id' => 'sometimes|integer|exists:manager_types,id',
            'settings' => 'nullable|array',
            'personal_permissions' => 'nullable|array',
            'personal_permissions.*' => ['string', Rule::in($allowedPermissions)],
            'use_personal_permissions' => 'boolean',
            'avatar_id' => 'nullable|integer|exists:files,id',
            'is_active' => 'boolean',
        ]);

        // Проверка прав на установку personal_permissions
        if ($error = $this->validatePermissionAssignment($data)) {
            return $error;
        }

        // Сохраняем старые значения для аудита
        $oldPermissions = $manager->personal_permissions;
        $oldUsePersonal = $manager->use_personal_permissions;

        if (isset($data['password'])) $data['password'] = Hash::make($data['password']);
        $manager->update($data);

        $this->logAction('update', 'manager', $manager->id, ['login' => $manager->login, 'name' => $manager->name]);

        // Аудит-лог при изменении прав
        $permissionsChanged = array_key_exists('personal_permissions', $data) && $data['personal_permissions'] !== $oldPermissions;
        $usePersonalChanged = array_key_exists('use_personal_permissions', $data) && (bool) ($data['use_personal_permissions'] ?? false) !== (bool) $oldUsePersonal;

        if ($permissionsChanged || $usePersonalChanged) {
            $this->logAction('permissions_changed', 'manager', $manager->id, [
                'old_permissions' => $oldPermissions,
                'new_permissions' => $data['personal_permissions'] ?? $oldPermissions,
                'old_use_personal' => $oldUsePersonal,
                'new_use_personal' => $data['use_personal_permissions'] ?? $oldUsePersonal,
                'changed_by' => $currentManager->id,
            ]);
        }

        return $this->success(new ManagerResource($manager->fresh(['managerType', 'avatar'])));
    }

    /** @OA\Delete(path="/managers/{id}", summary="Удалить менеджера", tags={"Managers"}, security={{"bearerAuth":{}}}, @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")), @OA\Response(response=200, description="Удалено")) */
    public function destroy(int $id): JsonResponse
    {
        if ($id === auth()->id()) {
            return $this->error('Нельзя удалить самого себя.', 422);
        }

        $manager = Manager::findOrFail($id);
        $login = $manager->login;
        $manager->delete();

        $this->logAction('delete', 'manager', $id, ['login' => $login]);

        return $this->success(null, 'Менеджер удалён.');
    }

    /** @OA\Get(path="/managers/{id}/sessions", summary="Сессии менеджера", tags={"Managers"}, security={{"bearerAuth":{}}}, @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")), @OA\Response(response=200, description="Список сессий")) */
    public function sessions(int $id): JsonResponse
    {
        $manager = Manager::findOrFail($id);
        $sessions = $manager->sessions()
            ->orderByDesc('last_active')
            ->get(['id', 'user_agent', 'ip', 'last_active', 'expires_at'])
            ->map(fn ($s) => [
                'id' => $s->id,
                'user_agent' => $s->user_agent,
                'ip' => $s->ip,
                'last_active' => $s->last_active?->toIso8601String(),
                'expires_at' => $s->expires_at?->toIso8601String(),
                'is_expired' => $s->expires_at && $s->expires_at->isPast(),
            ]);

        return $this->success($sessions);
    }

    /** @OA\Delete(path="/managers/{id}/sessions/{sessionId}", summary="Завершить сессию", tags={"Managers"}, security={{"bearerAuth":{}}}, @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")), @OA\Parameter(name="sessionId", in="path", required=true, @OA\Schema(type="integer")), @OA\Response(response=200, description="Сессия завершена")) */
    public function terminateSession(int $id, int $sessionId): JsonResponse
    {
        $session = ManagerSession::where('manager_id', $id)->findOrFail($sessionId);
        $session->delete();

        return $this->success(null, 'Сессия завершена.');
    }

    /** @OA\Delete(path="/managers/{id}/sessions", summary="Завершить все сессии", tags={"Managers"}, security={{"bearerAuth":{}}}, @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")), @OA\Response(response=200, description="Все сессии завершены")) */
    public function terminateAllSessions(int $id): JsonResponse
    {
        Manager::findOrFail($id);
        $count = ManagerSession::where('manager_id', $id)->delete();

        return $this->success(['deleted' => $count], 'Все сессии завершены.');
    }

    /**
     * Получить полный список допустимых значений permissions для валидации.
     *
     * Собирает permissions из ModuleRegistry (динамически из всех зарегистрированных модулей).
     * Если ModuleRegistry недоступен — использует fallback из config('cms.permissions').
     * Wildcard '*' всегда допустим (проверяется отдельно в validatePermissionAssignment).
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

        // Fallback: если модули не зарегистрированы, берём из конфига
        if (empty($permissions)) {
            $permissions = config('cms.permissions', []);
        }

        // Wildcard '*' — допустим (дополнительно проверяется в validatePermissionAssignment)
        $permissions[] = '*';

        return array_unique($permissions);
    }

    /**
     * Валидация назначения прав: проверка привилегий текущего менеджера.
     *
     * @return JsonResponse|null null если всё ОК, JsonResponse с ошибкой если запрещено
     */
    private function validatePermissionAssignment(array $data): ?JsonResponse
    {
        // Если personal_permissions не передаётся — нечего проверять
        if (!array_key_exists('personal_permissions', $data) || $data['personal_permissions'] === null) {
            return null;
        }

        /** @var Manager $currentManager */
        $currentManager = auth()->user();

        // 2. Установка personal_permissions разрешена только администраторам (*)
        //    или менеджерам, чьи права покрывают назначаемые
        $requestedPermissions = $data['personal_permissions'];

        // Назначение wildcard (*) разрешено только администраторам
        if (in_array('*', $requestedPermissions, true) && !$currentManager->isAdmin()) {
            $this->logAction('permissions_escalation_blocked', 'manager', null, [
                'attempted_permissions' => $requestedPermissions,
                'manager_id' => $currentManager->id,
            ]);
            return $this->error('Только администратор может назначить полные права (*).', 403);
        }

        // 3. Валидация: назначаемые права не должны превышать права текущего менеджера
        $exceeding = $currentManager->getExceedingPermissions($requestedPermissions);

        if (!empty($exceeding)) {
            $this->logAction('permissions_escalation_blocked', 'manager', null, [
                'attempted_permissions' => $requestedPermissions,
                'exceeding_permissions' => $exceeding,
                'manager_id' => $currentManager->id,
            ]);
            return $this->error(
                'Невозможно назначить права, превышающие ваши собственные: ' . implode(', ', $exceeding),
                403
            );
        }

        return null;
    }

    /** @OA\Delete(path="/managers/{id}/sessions/expired", summary="Удалить просроченные сессии", tags={"Managers"}, security={{"bearerAuth":{}}}, @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")), @OA\Response(response=200, description="Просроченные сессии удалены")) */
    public function terminateExpiredSessions(int $id): JsonResponse
    {
        Manager::findOrFail($id);
        $count = ManagerSession::where('manager_id', $id)->expired()->delete();

        return $this->success(['deleted' => $count], 'Просроченные сессии удалены.');
    }
}
