<?php

namespace Templite\Cms\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Templite\Cms\Models\Component;
use Templite\Cms\Services\BladeSecurityValidator;
use Templite\Cms\Services\PageAssetCompiler;

class ComponentCodeController extends Controller
{
    private function resolveComponentPath(Component $component): ?string
    {
        $appPath = app_path('View/Components/Cms/' . basename($component->slug));
        if (is_dir($appPath)) {
            return $appPath;
        }

        $storagePath = storage_path('cms/components/' . basename($component->slug));
        if (is_dir($storagePath)) {
            return $storagePath;
        }

        return null;
    }

    /** @OA\Get(path="/components/{id}/code", summary="Получить код компонента (template, style, script)", tags={"Component Code"}, security={{"bearerAuth":{}}}, @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")), @OA\Response(response=200, description="Код компонента")) */
    public function show(int $id): JsonResponse
    {
        $component = Component::findOrFail($id);
        $path = $this->resolveComponentPath($component);

        $code = ['template' => '', 'style' => '', 'script' => ''];

        if ($path) {
            if (file_exists($path . '/index.blade.php')) {
                $code['template'] = file_get_contents($path . '/index.blade.php');
            }
            if (file_exists($path . '/style.scss')) {
                $code['style'] = file_get_contents($path . '/style.scss');
            } elseif (file_exists($path . '/style.css')) {
                $code['style'] = file_get_contents($path . '/style.css');
            }
            if (file_exists($path . '/script.js')) {
                $code['script'] = file_get_contents($path . '/script.js');
            }
        }

        return $this->success($code);
    }

    /** @OA\Put(path="/components/{id}/code", summary="Сохранить код компонента", tags={"Component Code"}, security={{"bearerAuth":{}}}, @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")), @OA\RequestBody(required=true, @OA\JsonContent(@OA\Property(property="template", type="string"), @OA\Property(property="style", type="string"), @OA\Property(property="script", type="string"))), @OA\Response(response=200, description="Сохранено")) */
    public function update(Request $request, int $id): JsonResponse
    {
        $component = Component::findOrFail($id);
        $data = $request->validate([
            'template' => 'nullable|string',
            'style' => 'nullable|string',
            'script' => 'nullable|string',
        ]);

        // Валидация Blade-шаблона на запрещённые конструкции
        if (isset($data['template']) && $data['template'] !== '') {
            $bladeValidator = new BladeSecurityValidator();
            $violations = $bladeValidator->validate($data['template']);
            if (!empty($violations)) {
                return $this->error(
                    'Код содержит запрещённые конструкции: ' . implode(', ', $violations),
                    422
                );
            }
        }

        $path = storage_path('cms/components/' . basename($component->slug));
        if (!is_dir($path)) {
            mkdir($path, 0755, true);
        }

        if (array_key_exists('template', $data)) {
            file_put_contents($path . '/index.blade.php', $data['template'] ?? '');
        }
        if (array_key_exists('style', $data)) {
            $styleContent = $data['style'] ?? '';
            if ($styleContent === '') {
                @unlink($path . '/style.scss');
                @unlink($path . '/style.css');
            } else {
                file_put_contents($path . '/style.scss', $styleContent);
            }
        }
        if (array_key_exists('script', $data)) {
            $scriptContent = $data['script'] ?? '';
            if ($scriptContent === '') {
                @unlink($path . '/script.js');
            } else {
                file_put_contents($path . '/script.js', $scriptContent);
            }
        }

        if ($component->source === 'database') {
            $component->update(['source' => 'database', 'path' => 'storage/cms/components/' . basename($component->slug)]);
        }

        app(PageAssetCompiler::class)->recompileForComponent($component->slug);

        $this->logAction('update_code', 'component', $component->id, ['name' => $component->name]);

        return $this->success(null, 'Код компонента сохранён.');
    }
}
