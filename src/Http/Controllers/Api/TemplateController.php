<?php

namespace Templite\Cms\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Templite\Cms\Http\Resources\TemplatePageResource;
use Templite\Cms\Models\TemplatePage;
use Templite\Cms\Services\PageAssetCompiler;

class TemplateController extends Controller
{
    /** @OA\Get(path="/templates", summary="Список шаблонов", tags={"Templates"}, security={{"bearerAuth":{}}}, @OA\Response(response=200, description="Список")) */
    public function index(): JsonResponse
    {
        return $this->success(TemplatePageResource::collection(TemplatePage::with('screenshot')->get()));
    }

    /** @OA\Post(path="/templates", summary="Создать шаблон", tags={"Templates"}, security={{"bearerAuth":{}}}, @OA\RequestBody(required=true, @OA\JsonContent(required={"name","slug"}, @OA\Property(property="name", type="string"), @OA\Property(property="slug", type="string"))), @OA\Response(response=201, description="Создано")) */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => 'required|string|max:255', 'slug' => 'required|string|max:255|unique:template_pages',
            'settings' => 'nullable|array', 'screen' => 'nullable|integer|exists:files,id',
        ]);
        $template = TemplatePage::create($data);

        // Создаём директорию и копируем stub шаблона
        $templatePath = storage_path('cms/templates/' . basename($template->slug));
        if (!is_dir($templatePath)) {
            mkdir($templatePath, 0755, true);
        }
        $stubPath = __DIR__ . '/../../../../stubs/template.blade.php';
        if (file_exists($stubPath) && !file_exists($templatePath . '/template.blade.php')) {
            copy($stubPath, $templatePath . '/template.blade.php');
        }

        $this->logAction('create', 'template', $template->id, ['name' => $template->name, 'slug' => $template->slug]);

        return $this->success(new TemplatePageResource($template), 'Шаблон создан.', 201);
    }

    /** @OA\Get(path="/templates/{id}", summary="Получить шаблон", tags={"Templates"}, security={{"bearerAuth":{}}}, @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")), @OA\Response(response=200, description="Данные")) */
    public function show(int $id): JsonResponse
    {
        $template = TemplatePage::with(['screenshot', 'libraries'])->findOrFail($id);
        return $this->success(new TemplatePageResource($template));
    }

    /** @OA\Put(path="/templates/{id}", summary="Обновить шаблон", tags={"Templates"}, security={{"bearerAuth":{}}}, @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")), @OA\Response(response=200, description="Обновлено")) */
    public function update(Request $request, int $id): JsonResponse
    {
        $template = TemplatePage::findOrFail($id);
        $data = $request->validate([
            'name' => 'sometimes|string|max:255', 'slug' => 'sometimes|string|max:255|unique:template_pages,slug,' . $id,
            'settings' => 'nullable|array', 'screen' => 'nullable|integer|exists:files,id',
            'library_ids' => 'nullable|array',
            'library_ids.*' => 'integer|exists:libraries,id',
        ]);
        $template->update($data);

        if ($request->has('library_ids')) {
            $template->libraries()->sync($request->input('library_ids', []));
            app(PageAssetCompiler::class)->recompileForTemplate($template->id);
        }

        $this->logAction('update', 'template', $template->id, ['name' => $template->name]);

        return $this->success(new TemplatePageResource($template->fresh(['screenshot', 'libraries'])));
    }

    /** @OA\Delete(path="/templates/{id}", summary="Удалить шаблон", tags={"Templates"}, security={{"bearerAuth":{}}}, @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")), @OA\Response(response=200, description="Удалено")) */
    public function destroy(int $id): JsonResponse
    {
        $template = TemplatePage::findOrFail($id);
        $name = $template->name;
        $template->delete();

        $this->logAction('delete', 'template', $id, ['name' => $name]);

        return $this->success(null, 'Шаблон удалён.');
    }
}
