<?php

namespace Templite\Cms\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Templite\Cms\Http\Resources\LibraryResource;
use Templite\Cms\Helpers\StringHelper;
use Templite\Cms\Models\Library;

class LibraryController extends Controller
{
    /** @OA\Get(path="/libraries", summary="Список библиотек", tags={"Libraries"}, security={{"bearerAuth":{}}}, @OA\Parameter(name="active_only", in="query", @OA\Schema(type="boolean")), @OA\Parameter(name="search", in="query", @OA\Schema(type="string")), @OA\Response(response=200, description="Список библиотек")) */
    public function index(Request $request): JsonResponse
    {
        $query = Library::withCount(['blocks', 'templatePages']);

        if ($request->boolean('active_only')) {
            $query->active();
        }

        if ($request->has('search') && $request->search !== '') {
            $escaped = StringHelper::escapeLike($request->search);
            $query->where(function ($q) use ($escaped) {
                $q->where('name', 'like', "%{$escaped}%")
                  ->orWhere('slug', 'like', "%{$escaped}%")
                  ->orWhere('description', 'like', "%{$escaped}%");
            });
        }

        $allowedSortFields = ['name', 'slug', 'version', 'load_strategy', 'active', 'sort_order'];
        $sortField = in_array($request->input('sort_field'), $allowedSortFields, true)
            ? $request->input('sort_field')
            : 'sort_order';
        $sortOrder = $request->input('sort_order') === 'desc' ? 'desc' : 'asc';

        $query->orderBy($sortField, $sortOrder);
        if ($sortField !== 'name') {
            $query->orderBy('name');
        }

        $libraries = $query->get();

        return $this->success(LibraryResource::collection($libraries));
    }

    /** @OA\Post(path="/libraries", summary="Создать библиотеку", tags={"Libraries"}, security={{"bearerAuth":{}}}, @OA\RequestBody(required=true, @OA\JsonContent(required={"name","slug","load_strategy"}, @OA\Property(property="name", type="string"), @OA\Property(property="slug", type="string"), @OA\Property(property="version", type="string"), @OA\Property(property="description", type="string"), @OA\Property(property="js_cdn", type="string"), @OA\Property(property="css_cdn", type="string"), @OA\Property(property="load_strategy", type="string", enum={"local","cdn"}), @OA\Property(property="sort_order", type="integer"), @OA\Property(property="active", type="boolean"))), @OA\Response(response=201, description="Библиотека создана")) */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'slug' => ['required', 'string', 'max:255', 'unique:libraries', 'regex:/^[a-z][a-z0-9\-]*$/'],
            'version' => 'nullable|string|max:50',
            'description' => 'nullable|string|max:1000',
            'js_cdn' => 'nullable|url|max:500',
            'css_cdn' => 'nullable|url|max:500',
            'load_strategy' => 'required|string|in:local,cdn',
            'sort_order' => 'integer',
            'active' => 'boolean',
        ]);

        $library = Library::create($data);

        return $this->success(
            new LibraryResource($library->loadCount(['blocks', 'templatePages'])),
            'Библиотека создана.',
            201
        );
    }

    /** @OA\Get(path="/libraries/{id}", summary="Получить библиотеку", tags={"Libraries"}, security={{"bearerAuth":{}}}, @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")), @OA\Response(response=200, description="Данные библиотеки")) */
    public function show(int $id): JsonResponse
    {
        $library = Library::withCount(['blocks', 'templatePages'])->findOrFail($id);

        return $this->success(new LibraryResource($library));
    }

    /** @OA\Put(path="/libraries/{id}", summary="Обновить библиотеку", tags={"Libraries"}, security={{"bearerAuth":{}}}, @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")), @OA\RequestBody(required=true, @OA\JsonContent(@OA\Property(property="name", type="string"), @OA\Property(property="slug", type="string"), @OA\Property(property="version", type="string"), @OA\Property(property="description", type="string"), @OA\Property(property="js_cdn", type="string"), @OA\Property(property="css_cdn", type="string"), @OA\Property(property="load_strategy", type="string", enum={"local","cdn"}), @OA\Property(property="sort_order", type="integer"), @OA\Property(property="active", type="boolean"))), @OA\Response(response=200, description="Библиотека обновлена")) */
    public function update(Request $request, int $id): JsonResponse
    {
        $library = Library::findOrFail($id);

        $data = $request->validate([
            'name' => 'sometimes|string|max:255',
            'slug' => ['sometimes', 'string', 'max:255', 'unique:libraries,slug,' . $id, 'regex:/^[a-z][a-z0-9\-]*$/'],
            'version' => 'nullable|string|max:50',
            'description' => 'nullable|string|max:1000',
            'js_cdn' => 'nullable|url|max:500',
            'css_cdn' => 'nullable|url|max:500',
            'load_strategy' => 'sometimes|string|in:local,cdn',
            'sort_order' => 'integer',
            'active' => 'boolean',
        ]);

        $library->update($data);

        app(\Templite\Cms\Services\PageAssetCompiler::class)->recompileForLibrary($library->id);

        return $this->success(
            new LibraryResource($library->fresh()->loadCount(['blocks', 'templatePages'])),
            'Библиотека обновлена.'
        );
    }

    /** @OA\Delete(path="/libraries/{id}", summary="Удалить библиотеку", tags={"Libraries"}, security={{"bearerAuth":{}}}, @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")), @OA\Response(response=200, description="Библиотека удалена"), @OA\Response(response=422, description="Библиотека используется")) */
    public function destroy(int $id): JsonResponse
    {
        $library = Library::withCount(['blocks', 'templatePages'])->findOrFail($id);

        if ($library->blocks_count > 0 || $library->template_pages_count > 0) {
            return $this->error(
                'Невозможно удалить библиотеку, так как она используется в блоках или шаблонах.',
                422
            );
        }

        // Удаляем загруженные файлы
        $dir = 'cms/libraries/' . basename($library->slug);
        if (Storage::disk('public')->exists($dir)) {
            Storage::disk('public')->deleteDirectory($dir);
        }

        $library->delete();

        return $this->success(null, 'Библиотека удалена.');
    }

    /**
     * Допустимые MIME-типы для файлов библиотек.
     */
    private const JS_ALLOWED_MIMES = ['application/javascript', 'text/javascript', 'application/x-javascript', 'text/plain'];
    private const CSS_ALLOWED_MIMES = ['text/css', 'text/plain'];

    /** @OA\Post(path="/libraries/{id}/upload", summary="Загрузить JS/CSS файлы библиотеки", tags={"Libraries"}, security={{"bearerAuth":{}}}, @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")), @OA\RequestBody(required=true, @OA\MediaType(mediaType="multipart/form-data", @OA\Schema(@OA\Property(property="js_file", type="string", format="binary"), @OA\Property(property="css_file", type="string", format="binary")))), @OA\Response(response=200, description="Файлы загружены"), @OA\Response(response=422, description="Ошибка валидации")) */
    public function upload(Request $request, int $id): JsonResponse
    {
        $library = Library::findOrFail($id);

        // Валидация: только проверяем что это файл и размер.
        // Расширение и MIME проверяются ниже вручную (defense in depth),
        // т.к. Laravel mimes/mimetypes не надёжны для .js/.css (часто text/plain).
        $request->validate([
            'js_file' => 'nullable|file|max:5120',
            'css_file' => 'nullable|file|max:5120',
        ]);

        $dir = 'cms/libraries/' . basename($library->slug);
        $updateData = [];

        if ($request->hasFile('js_file')) {
            $jsFile = $request->file('js_file');

            // Дополнительная проверка расширения (defense in depth)
            if (strtolower($jsFile->getClientOriginalExtension()) !== 'js') {
                return $this->error('JS-файл должен иметь расширение .js', 422);
            }

            // Проверка MIME-типа через содержимое файла
            if (!in_array($jsFile->getMimeType(), self::JS_ALLOWED_MIMES, true)) {
                return $this->error('Недопустимый MIME-тип для JS-файла.', 422);
            }

            // Удаляем старый JS файл
            if ($library->js_file && Storage::disk('public')->exists($library->js_file)) {
                Storage::disk('public')->delete($library->js_file);
            }

            // Генерируем безопасное имя файла (slug + уникальный хэш + расширение)
            $jsFileName = $library->slug . '-' . Str::random(8) . '.js';
            $jsPath = $jsFile->storeAs($dir, $jsFileName, 'public');
            $updateData['js_file'] = $jsPath;
        }

        if ($request->hasFile('css_file')) {
            $cssFile = $request->file('css_file');

            // Дополнительная проверка расширения (defense in depth)
            if (strtolower($cssFile->getClientOriginalExtension()) !== 'css') {
                return $this->error('CSS-файл должен иметь расширение .css', 422);
            }

            // Проверка MIME-типа через содержимое файла
            if (!in_array($cssFile->getMimeType(), self::CSS_ALLOWED_MIMES, true)) {
                return $this->error('Недопустимый MIME-тип для CSS-файла.', 422);
            }

            // Удаляем старый CSS файл
            if ($library->css_file && Storage::disk('public')->exists($library->css_file)) {
                Storage::disk('public')->delete($library->css_file);
            }

            // Генерируем безопасное имя файла (slug + уникальный хэш + расширение)
            $cssFileName = $library->slug . '-' . Str::random(8) . '.css';
            $cssPath = $cssFile->storeAs($dir, $cssFileName, 'public');
            $updateData['css_file'] = $cssPath;
        }

        if (!empty($updateData)) {
            $library->update($updateData);
        }

        app(\Templite\Cms\Services\PageAssetCompiler::class)->recompileForLibrary($library->id);

        return $this->success(
            new LibraryResource($library->fresh()->loadCount(['blocks', 'templatePages'])),
            'Файлы загружены.'
        );
    }
}
