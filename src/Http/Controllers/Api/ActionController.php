<?php

namespace Templite\Cms\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Templite\Cms\Contracts\BlockActionInterface;
use Templite\Cms\Http\Resources\ActionResource;
use Templite\Cms\Models\Action;
use Templite\Cms\Models\Page;
use Templite\Cms\Services\ActionCodeValidator;
use Templite\Cms\Services\ActionRunner;

class ActionController extends Controller
{
    protected ActionCodeValidator $codeValidator;

    public function __construct(ActionCodeValidator $codeValidator)
    {
        $this->codeValidator = $codeValidator;
    }

    /**
     * Валидация кода Action через токенизатор (whitelist-подход).
     *
     * Использует token_get_all() для разбора PHP-кода вместо regex-blacklist.
     * Разрешены только функции и классы из whitelist.
     *
     * @throws ValidationException если код содержит запрещённые конструкции
     */
    protected function validateActionCode(string $code): void
    {
        $errors = $this->codeValidator->validate($code);

        if (!empty($errors)) {
            throw ValidationException::withMessages([
                'code' => $errors,
            ]);
        }
    }

    /** @OA\Get(path="/actions", summary="Список actions", tags={"Actions"}, security={{"bearerAuth":{}}}, @OA\Response(response=200, description="Список")) */
    public function index(): JsonResponse
    {
        return $this->success(ActionResource::collection(Action::all()));
    }

    /** @OA\Post(path="/actions", summary="Создать action", tags={"Actions"}, security={{"bearerAuth":{}}}, @OA\RequestBody(required=true, @OA\JsonContent(required={"name","slug"}, @OA\Property(property="name", type="string"), @OA\Property(property="slug", type="string"))), @OA\Response(response=201, description="Создано")) */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => 'required|string|max:255', 'slug' => 'required|string|max:255|unique:actions',
            'class_name' => 'nullable|string', 'source' => 'string|in:vendor,app,storage',
            'params' => 'nullable|array', 'returns' => 'nullable|array', 'description' => 'nullable|string',
        ]);
        $action = Action::create($data);

        $this->logAction('create', 'action', $action->id, ['name' => $action->name, 'slug' => $action->slug]);

        return $this->success(new ActionResource($action), 'Action создан.', 201);
    }

    /** @OA\Get(path="/actions/{id}", summary="Получить action", tags={"Actions"}, security={{"bearerAuth":{}}}, @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")), @OA\Response(response=200, description="Данные")) */
    public function show(int $id): JsonResponse
    {
        $action = Action::findOrFail($id);
        $data = (new ActionResource($action))->toArray(request());
        $data['code'] = $this->resolveActionCode($action);
        return $this->success($data);
    }

    protected function resolveActionCode(Action $action): ?string
    {
        $storagePath = storage_path('cms/actions/' . basename($action->slug) . '.php');
        if (file_exists($storagePath)) {
            return file_get_contents($storagePath);
        }

        if ($action->class_name && class_exists($action->class_name)) {
            try {
                $reflection = new \ReflectionClass($action->class_name);
                $filePath = $reflection->getFileName();
                if ($filePath && file_exists($filePath)) {
                    return file_get_contents($filePath);
                }
            } catch (\ReflectionException $e) {
            }
        }

        return null;
    }

    /** @OA\Put(path="/actions/{id}", summary="Обновить action", tags={"Actions"}, security={{"bearerAuth":{}}}, @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")), @OA\Response(response=200, description="Обновлено")) */
    public function update(Request $request, int $id): JsonResponse
    {
        $action = Action::findOrFail($id);
        $data = $request->validate([
            'name' => 'sometimes|string|max:255', 'slug' => 'sometimes|string|max:255|unique:actions,slug,' . $id,
            'class_name' => 'nullable|string',
            'description' => 'nullable|string', 'code' => 'nullable|string',
        ]);

        if (isset($data['code'])) {
            // Проверка permission: редактирование кода Actions требует actions.code
            $manager = auth()->user();
            if (!$manager || !$manager->hasPermission('actions.code')) {
                return $this->error('Недостаточно прав для редактирования кода Actions.', 403);
            }

            // Валидация кода через токенизатор (whitelist-подход)
            $this->validateActionCode($data['code']);

            $path = storage_path('cms/actions');
            if (!is_dir($path)) {
                mkdir($path, 0755, true);
            }

            $filePath = $path . '/' . basename($action->slug) . '.php';
            file_put_contents($filePath, $data['code']);

            // Вычисляем и сохраняем хэш кода для проверки целостности
            $data['code_hash'] = $this->codeValidator->hashCode($data['code']);

            $parsed = $this->parseActionMethods($data['code'], $filePath);
            $data['params'] = $parsed['params'];
            $data['returns'] = $parsed['returns'];

            // Аудит-лог: изменение кода Action (хэш, длина, превью)
            $this->logAction('action.code.updated', 'action', $action->id, [
                'name' => $action->name,
                'slug' => $action->slug,
                'code_length' => strlen($data['code']),
                'code_hash' => $data['code_hash'],
                'code_preview' => mb_substr($data['code'], 0, 200),
                'manager_id' => $manager->id,
                'manager_login' => $manager->login ?? $manager->email ?? null,
                'ip' => request()->ip(),
            ]);

            unset($data['code']);
        }

        $action->update($data);

        $this->logAction('update', 'action', $action->id, ['name' => $action->name]);

        return $this->success(new ActionResource($action->fresh()));
    }

    protected function parseActionMethods(string $code, string $filePath): array
    {
        $result = ['params' => null, 'returns' => null];

        try {
            $className = $this->extractClassName($code);
            if (!$className) {
                return $result;
            }

            if (!class_exists($className, false)) {
                require_once $filePath;
            }

            if (!class_exists($className)) {
                return $result;
            }

            $instance = new $className();

            if ($instance instanceof BlockActionInterface) {
                $result['params'] = $instance->params();
                $result['returns'] = $instance->returns();
            }
        } catch (\Throwable $e) {
        }

        return $result;
    }

    protected function extractClassName(string $code): ?string
    {
        $namespace = null;
        $class = null;

        if (preg_match('/namespace\s+([\w\\\\]+)\s*;/', $code, $m)) {
            $namespace = $m[1];
        }

        if (preg_match('/class\s+(\w+)/', $code, $m)) {
            $class = $m[1];
        }

        if (!$class) {
            return null;
        }

        return $namespace ? $namespace . '\\' . $class : $class;
    }

    /** @OA\Delete(path="/actions/{id}", summary="Удалить action", tags={"Actions"}, security={{"bearerAuth":{}}}, @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")), @OA\Response(response=200, description="Удалено")) */
    public function destroy(int $id): JsonResponse
    {
        $action = Action::findOrFail($id);
        $name = $action->name;
        $action->delete();

        $this->logAction('delete', 'action', $id, ['name' => $name]);

        return $this->success(null, 'Action удалён.');
    }

    /** @OA\Post(path="/actions/{id}/test", summary="Тестировать action", tags={"Actions"}, security={{"bearerAuth":{}}}, @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")), @OA\Response(response=200, description="Результат")) */
    public function test(Request $request, int $id, ActionRunner $runner): JsonResponse
    {
        $action = Action::findOrFail($id);
        $params = $request->input('params', []);
        $pageId = $request->input('page_id');
        $page = $pageId ? Page::findOrFail($pageId) : Page::first();

        if (!$page) {
            return $this->error('Нет страниц для тестирования.', 400);
        }

        try {
            $result = $runner->runSingle($action->slug, $params, $page, $request);
            return $this->success($result, 'Тест выполнен успешно.');
        } catch (\Throwable $e) {
            return $this->error('Ошибка: ' . $e->getMessage(), 500);
        }
    }
}
