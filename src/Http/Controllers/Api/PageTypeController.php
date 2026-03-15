<?php

namespace Templite\Cms\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Templite\Cms\Http\Resources\PageTypeResource;
use Templite\Cms\Models\PageType;

class PageTypeController extends Controller
{
    /**
     * @OA\Get(path="/page-types", summary="Список типов страниц", tags={"Page Types"},
     *     security={{"bearerAuth":{}}},
     *     @OA\Response(response=200, description="Список"))
     */
    public function index(): JsonResponse
    {
        $types = PageType::withCount('pages')->with('attributes')->get();
        return $this->success(PageTypeResource::collection($types));
    }

    /**
     * @OA\Post(path="/page-types", summary="Создать тип страницы", tags={"Page Types"},
     *     security={{"bearerAuth":{}}},
     *     @OA\RequestBody(required=true, @OA\JsonContent(
     *         required={"name","slug"},
     *         @OA\Property(property="name", type="string"),
     *         @OA\Property(property="slug", type="string"),
     *         @OA\Property(property="template_page_id", type="integer"),
     *         @OA\Property(property="settings", type="object")
     *     )),
     *     @OA\Response(response=201, description="Создано"))
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'slug' => 'required|string|max:255|unique:page_types',
            'template_page_id' => 'nullable|integer|exists:template_pages,id',
            'settings' => 'nullable|array',
        ]);

        $type = PageType::create($data);
        $this->logAction('create', 'page_type', $type->id, ['name' => $type->name, 'slug' => $type->slug]);
        return $this->success(new PageTypeResource($type), 'Тип страницы создан.', 201);
    }

    /**
     * @OA\Get(path="/page-types/{id}", summary="Получить тип страницы", tags={"Page Types"},
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
     *     @OA\Response(response=200, description="Данные типа"))
     */
    public function show(int $id): JsonResponse
    {
        $type = PageType::with('attributes')->withCount('pages')->findOrFail($id);
        return $this->success(new PageTypeResource($type));
    }

    /**
     * @OA\Put(path="/page-types/{id}", summary="Обновить тип страницы", tags={"Page Types"},
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
     *     @OA\RequestBody(required=true, @OA\JsonContent(
     *         @OA\Property(property="name", type="string"),
     *         @OA\Property(property="slug", type="string"),
     *         @OA\Property(property="settings", type="object")
     *     )),
     *     @OA\Response(response=200, description="Обновлено"))
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $type = PageType::findOrFail($id);
        $data = $request->validate([
            'name' => 'sometimes|string|max:255',
            'slug' => 'sometimes|string|max:255|unique:page_types,slug,' . $id,
            'template_page_id' => 'nullable|integer|exists:template_pages,id',
            'settings' => 'nullable|array',
        ]);
        $type->update($data);
        $this->logAction('update', 'page_type', $type->id, ['name' => $type->name]);
        return $this->success(new PageTypeResource($type->fresh('attributes')));
    }

    /**
     * @OA\Delete(path="/page-types/{id}", summary="Удалить тип страницы", tags={"Page Types"},
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
     *     @OA\Response(response=200, description="Удалено"))
     */
    public function destroy(int $id): JsonResponse
    {
        $type = PageType::findOrFail($id);
        $name = $type->name;
        $type->delete();
        $this->logAction('delete', 'page_type', $id, ['name' => $name]);
        return $this->success(null, 'Тип страницы удалён.');
    }
}
