<?php

namespace Templite\Cms\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Templite\Cms\Http\Resources\CityResource;
use Templite\Cms\Helpers\StringHelper;
use Templite\Cms\Models\City;

/**
 * @OA\Tag(name="Cities", description="Города")
 */
class CityController extends Controller
{
    /**
     * @OA\Get(
     *     path="/cities",
     *     summary="Список городов",
     *     tags={"Cities"},
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(name="search", in="query", @OA\Schema(type="string")),
     *     @OA\Parameter(name="is_active", in="query", @OA\Schema(type="boolean")),
     *     @OA\Response(response=200, description="Список городов")
     * )
     */
    public function index(Request $request): JsonResponse
    {
        $query = City::query();

        if ($request->has('search')) {
            $query->where('name', 'like', '%' . StringHelper::escapeLike($request->search) . '%');
        }

        if ($request->has('is_active')) {
            $query->where('is_active', filter_var($request->is_active, FILTER_VALIDATE_BOOLEAN));
        }

        $cities = $query->ordered()->get();

        return $this->success(CityResource::collection($cities));
    }

    /**
     * @OA\Post(
     *     path="/cities",
     *     summary="Создать город",
     *     tags={"Cities"},
     *     security={{"bearerAuth":{}}},
     *     @OA\RequestBody(required=true, @OA\JsonContent(
     *         required={"name","slug"},
     *         @OA\Property(property="name", type="string"),
     *         @OA\Property(property="slug", type="string"),
     *         @OA\Property(property="name_genitive", type="string"),
     *         @OA\Property(property="name_prepositional", type="string"),
     *         @OA\Property(property="name_accusative", type="string"),
     *         @OA\Property(property="region", type="string"),
     *         @OA\Property(property="phone", type="string"),
     *         @OA\Property(property="address", type="string"),
     *         @OA\Property(property="email", type="string"),
     *         @OA\Property(property="coordinates", type="object"),
     *         @OA\Property(property="extra_data", type="object"),
     *         @OA\Property(property="is_default", type="boolean"),
     *         @OA\Property(property="is_active", type="boolean")
     *     )),
     *     @OA\Response(response=201, description="Город создан"),
     *     @OA\Response(response=422, description="Ошибка валидации")
     * )
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'slug' => 'required|string|max:100|unique:cities,slug|regex:/^[a-z0-9-]+$/',
            'name_genitive' => 'nullable|string|max:255',
            'name_prepositional' => 'nullable|string|max:255',
            'name_accusative' => 'nullable|string|max:255',
            'region' => 'nullable|string|max:255',
            'phone' => 'nullable|string|max:50',
            'address' => 'nullable|string',
            'email' => 'nullable|email|max:255',
            'coordinates' => 'nullable|array',
            'coordinates.lat' => 'nullable|numeric',
            'coordinates.lng' => 'nullable|numeric',
            'extra_data' => 'nullable|array',
            'is_default' => 'boolean',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ]);

        $city = DB::transaction(function () use ($data) {
            // Если этот город — по умолчанию, снимаем флаг с других
            if (!empty($data['is_default'])) {
                City::where('is_default', true)->update(['is_default' => false]);
            }

            return City::create($data);
        });

        City::clearCache();

        $this->logAction('create', 'city', $city->id, ['name' => $city->name, 'slug' => $city->slug]);

        return $this->success(new CityResource($city), 'Город создан.', 201);
    }

    /**
     * @OA\Get(
     *     path="/cities/{id}",
     *     summary="Получить город",
     *     tags={"Cities"},
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
     *     @OA\Response(response=200, description="Данные города"),
     *     @OA\Response(response=404, description="Не найден")
     * )
     */
    public function show(int $id): JsonResponse
    {
        $city = City::findOrFail($id);

        return $this->success(new CityResource($city));
    }

    /**
     * @OA\Put(
     *     path="/cities/{id}",
     *     summary="Обновить город",
     *     tags={"Cities"},
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
     *     @OA\Response(response=200, description="Обновлено"),
     *     @OA\Response(response=404, description="Не найден")
     * )
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $city = City::findOrFail($id);

        $data = $request->validate([
            'name' => 'sometimes|string|max:255',
            'slug' => 'sometimes|string|max:100|unique:cities,slug,' . $id . '|regex:/^[a-z0-9-]+$/',
            'name_genitive' => 'nullable|string|max:255',
            'name_prepositional' => 'nullable|string|max:255',
            'name_accusative' => 'nullable|string|max:255',
            'region' => 'nullable|string|max:255',
            'phone' => 'nullable|string|max:50',
            'address' => 'nullable|string',
            'email' => 'nullable|email|max:255',
            'coordinates' => 'nullable|array',
            'coordinates.lat' => 'nullable|numeric',
            'coordinates.lng' => 'nullable|numeric',
            'extra_data' => 'nullable|array',
            'is_default' => 'boolean',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ]);

        DB::transaction(function () use ($city, $data) {
            if (!empty($data['is_default'])) {
                City::where('is_default', true)
                    ->where('id', '!=', $city->id)
                    ->update(['is_default' => false]);
            }

            $city->update($data);
        });

        City::clearCache();

        $this->logAction('update', 'city', $city->id, ['name' => $city->name]);

        return $this->success(new CityResource($city->fresh()));
    }

    /**
     * @OA\Delete(
     *     path="/cities/{id}",
     *     summary="Удалить город",
     *     tags={"Cities"},
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
     *     @OA\Response(response=200, description="Удалено"),
     *     @OA\Response(response=404, description="Не найден")
     * )
     */
    public function destroy(int $id): JsonResponse
    {
        $city = City::findOrFail($id);
        $name = $city->name;
        $city->delete();

        City::clearCache();

        $this->logAction('delete', 'city', $id, ['name' => $name]);

        return $this->success(null, 'Город удалён.');
    }

    /**
     * @OA\Put(
     *     path="/cities/reorder",
     *     summary="Изменить порядок городов",
     *     tags={"Cities"},
     *     security={{"bearerAuth":{}}},
     *     @OA\Response(response=200, description="Порядок обновлён")
     * )
     */
    public function reorder(Request $request): JsonResponse
    {
        $data = $request->validate([
            'items' => 'required|array',
            'items.*.id' => 'required|integer|exists:cities,id',
            'items.*.sort_order' => 'required|integer',
        ]);

        foreach ($data['items'] as $item) {
            City::where('id', $item['id'])->update(['sort_order' => $item['sort_order']]);
        }

        City::clearCache();

        return $this->success(null, 'Порядок обновлён.');
    }

    /**
     * @OA\Post(
     *     path="/cities/import",
     *     summary="Импорт городов из CSV",
     *     tags={"Cities"},
     *     security={{"bearerAuth":{}}},
     *     @OA\RequestBody(required=true, @OA\MediaType(
     *         mediaType="multipart/form-data",
     *         @OA\Schema(@OA\Property(property="file", type="string", format="binary"))
     *     )),
     *     @OA\Response(response=200, description="Импортировано")
     * )
     */
    public function import(Request $request): JsonResponse
    {
        $request->validate([
            'file' => 'required|file|mimes:csv,txt|max:5120',
        ]);

        $file = $request->file('file');
        $handle = fopen($file->getRealPath(), 'r');

        if (!$handle) {
            return $this->error('Не удалось прочитать файл.', 422);
        }

        // Первая строка — заголовки
        $headers = fgetcsv($handle, 0, ',');

        if (!$headers) {
            fclose($handle);
            return $this->error('Файл пуст.', 422);
        }

        $headers = array_map('trim', $headers);
        $headers = array_map('mb_strtolower', $headers);

        $imported = 0;
        $skipped = 0;

        while (($row = fgetcsv($handle, 0, ',')) !== false) {
            $data = array_combine($headers, array_pad($row, count($headers), null));

            if (empty($data['name']) || empty($data['slug'])) {
                $skipped++;
                continue;
            }

            // Пропускаем дубликаты по slug
            if (City::where('slug', $data['slug'])->exists()) {
                $skipped++;
                continue;
            }

            City::create([
                'name' => trim($data['name']),
                'slug' => trim($data['slug']),
                'name_genitive' => isset($data['name_genitive']) ? trim($data['name_genitive']) : null,
                'name_prepositional' => isset($data['name_prepositional']) ? trim($data['name_prepositional']) : null,
                'name_accusative' => isset($data['name_accusative']) ? trim($data['name_accusative']) : null,
                'region' => isset($data['region']) ? trim($data['region']) : null,
                'phone' => isset($data['phone']) ? trim($data['phone']) : null,
                'address' => isset($data['address']) ? trim($data['address']) : null,
                'email' => isset($data['email']) ? trim($data['email']) : null,
                'is_active' => true,
                'sort_order' => $imported,
            ]);

            $imported++;
        }

        fclose($handle);

        City::clearCache();

        $this->logAction('import', 'city', null, ['imported' => $imported, 'skipped' => $skipped]);

        return $this->success([
            'imported' => $imported,
            'skipped' => $skipped,
        ], "Импортировано: {$imported}, пропущено: {$skipped}.");
    }
}
