<?php

namespace Templite\Cms\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Templite\Cms\Http\Resources\LanguageResource;
use Templite\Cms\Models\Language;

/**
 * @OA\Tag(name="Languages", description="Языки")
 */
class LanguageController extends Controller
{
    /**
     * @OA\Get(
     *     path="/languages",
     *     summary="Список языков",
     *     tags={"Languages"},
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(name="is_active", in="query", @OA\Schema(type="boolean")),
     *     @OA\Response(response=200, description="Список языков")
     * )
     */
    public function index(Request $request): JsonResponse
    {
        $query = Language::query();

        if ($request->has('is_active')) {
            $query->where('is_active', filter_var($request->is_active, FILTER_VALIDATE_BOOLEAN));
        }

        $languages = $query->ordered()->get();

        return $this->success(LanguageResource::collection($languages));
    }

    /**
     * @OA\Post(
     *     path="/languages",
     *     summary="Создать язык",
     *     tags={"Languages"},
     *     security={{"bearerAuth":{}}},
     *     @OA\RequestBody(required=true, @OA\JsonContent(
     *         required={"code","name"},
     *         @OA\Property(property="code", type="string", maxLength=5),
     *         @OA\Property(property="name", type="string", maxLength=255),
     *         @OA\Property(property="is_default", type="boolean"),
     *         @OA\Property(property="is_active", type="boolean"),
     *         @OA\Property(property="order", type="integer")
     *     )),
     *     @OA\Response(response=201, description="Язык создан"),
     *     @OA\Response(response=422, description="Ошибка валидации")
     * )
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'code' => 'required|string|max:5|unique:languages,code',
            'name' => 'required|string|max:255',
            'is_default' => 'boolean',
            'is_active' => 'boolean',
            'order' => 'integer',
        ]);

        $language = DB::transaction(function () use ($data) {
            // Если этот язык — по умолчанию, снимаем флаг с других
            if (!empty($data['is_default'])) {
                Language::where('is_default', true)->update(['is_default' => false]);
            }

            // Если order не указан, ставим в конец
            if (!isset($data['order'])) {
                $data['order'] = (Language::max('order') ?? -1) + 1;
            }

            return Language::create($data);
        });

        $this->logAction('create', 'language', $language->id, ['code' => $language->code, 'name' => $language->name]);
        Language::clearCache();

        return $this->success(new LanguageResource($language), 'Язык создан.', 201);
    }

    /**
     * @OA\Get(
     *     path="/languages/{id}",
     *     summary="Получить язык",
     *     tags={"Languages"},
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
     *     @OA\Response(response=200, description="Данные языка"),
     *     @OA\Response(response=404, description="Не найден")
     * )
     */
    public function show(int $id): JsonResponse
    {
        $language = Language::findOrFail($id);

        return $this->success(new LanguageResource($language));
    }

    /**
     * @OA\Put(
     *     path="/languages/{id}",
     *     summary="Обновить язык",
     *     tags={"Languages"},
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
     *     @OA\RequestBody(required=true, @OA\JsonContent(
     *         @OA\Property(property="code", type="string", maxLength=5),
     *         @OA\Property(property="name", type="string", maxLength=255),
     *         @OA\Property(property="is_default", type="boolean"),
     *         @OA\Property(property="is_active", type="boolean"),
     *         @OA\Property(property="order", type="integer")
     *     )),
     *     @OA\Response(response=200, description="Обновлено"),
     *     @OA\Response(response=404, description="Не найден")
     * )
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $language = Language::findOrFail($id);

        $data = $request->validate([
            'code' => 'sometimes|string|max:5|unique:languages,code,' . $id,
            'name' => 'sometimes|string|max:255',
            'is_default' => 'boolean',
            'is_active' => 'boolean',
            'order' => 'integer',
        ]);

        DB::transaction(function () use ($language, $data) {
            if (!empty($data['is_default'])) {
                Language::where('is_default', true)
                    ->where('id', '!=', $language->id)
                    ->update(['is_default' => false]);
            }

            $language->update($data);
        });

        $this->logAction('update', 'language', $language->id, ['code' => $language->code, 'name' => $language->name]);
        Language::clearCache();

        return $this->success(new LanguageResource($language->fresh()));
    }

    /**
     * @OA\Delete(
     *     path="/languages/{id}",
     *     summary="Удалить язык",
     *     tags={"Languages"},
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
     *     @OA\Response(response=200, description="Удалено"),
     *     @OA\Response(response=404, description="Не найден"),
     *     @OA\Response(response=409, description="Нельзя удалить язык по умолчанию")
     * )
     */
    public function destroy(int $id): JsonResponse
    {
        $language = Language::findOrFail($id);

        if ($language->is_default) {
            return $this->error('Нельзя удалить язык по умолчанию. Сначала назначьте другой язык по умолчанию.', 409);
        }

        $name = $language->name;
        $code = $language->code;

        // Каскадное удаление переводов через FK (ON DELETE CASCADE)
        $language->delete();

        $this->logAction('delete', 'language', $id, ['code' => $code, 'name' => $name]);
        Language::clearCache();

        return $this->success(null, 'Язык удалён.');
    }

    /**
     * @OA\Put(
     *     path="/languages/{id}/set-default",
     *     summary="Назначить язык по умолчанию",
     *     tags={"Languages"},
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
     *     @OA\Response(response=200, description="Язык назначен по умолчанию"),
     *     @OA\Response(response=404, description="Не найден")
     * )
     */
    public function setDefault(int $id): JsonResponse
    {
        $language = Language::findOrFail($id);

        DB::transaction(function () use ($language) {
            Language::where('is_default', true)
                ->where('id', '!=', $language->id)
                ->update(['is_default' => false]);

            $language->update([
                'is_default' => true,
                'is_active' => true,
            ]);
        });

        $this->logAction('set_default', 'language', $language->id, ['code' => $language->code, 'name' => $language->name]);
        Language::clearCache();

        return $this->success(new LanguageResource($language->fresh()), 'Язык по умолчанию обновлён.');
    }

    /**
     * @OA\Put(
     *     path="/languages/reorder",
     *     summary="Изменить порядок языков",
     *     tags={"Languages"},
     *     security={{"bearerAuth":{}}},
     *     @OA\RequestBody(required=true, @OA\JsonContent(
     *         required={"items"},
     *         @OA\Property(property="items", type="array", @OA\Items(
     *             @OA\Property(property="id", type="integer"),
     *             @OA\Property(property="order", type="integer")
     *         ))
     *     )),
     *     @OA\Response(response=200, description="Порядок обновлён")
     * )
     */
    public function reorder(Request $request): JsonResponse
    {
        $data = $request->validate([
            'items' => 'required|array',
            'items.*.id' => 'required|integer|exists:languages,id',
            'items.*.order' => 'required|integer',
        ]);

        foreach ($data['items'] as $item) {
            Language::where('id', $item['id'])->update(['order' => $item['order']]);
        }

        $this->logAction('reorder', 'language', null);
        Language::clearCache();

        return $this->success(null, 'Порядок обновлён.');
    }
}
