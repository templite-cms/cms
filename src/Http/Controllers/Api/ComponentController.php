<?php

namespace Templite\Cms\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Blade;
use Templite\Cms\Http\Resources\ComponentResource;
use Templite\Cms\Models\Component;
use Templite\Cms\Models\TemplatePage;
use Templite\Cms\Services\BlockRenderer;

class ComponentController extends Controller
{
    /** @OA\Get(path="/components", summary="Список компонентов", tags={"Components"}, security={{"bearerAuth":{}}}, @OA\Response(response=200, description="Список")) */
    public function index(): JsonResponse
    {
        return $this->success(ComponentResource::collection(Component::all()));
    }

    /** @OA\Post(path="/components", summary="Создать компонент", tags={"Components"}, security={{"bearerAuth":{}}}, @OA\RequestBody(required=true, @OA\JsonContent(required={"name","slug"}, @OA\Property(property="name", type="string"), @OA\Property(property="slug", type="string"))), @OA\Response(response=201, description="Создано")) */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => 'required|string|max:255', 'slug' => 'required|string|max:255|unique:components',
            'source' => 'string|in:database,file,vendor', 'params' => 'nullable|array', 'description' => 'nullable|string',
        ]);
        $component = Component::create($data);

        $this->logAction('create', 'component', $component->id, ['name' => $component->name, 'slug' => $component->slug]);

        return $this->success(new ComponentResource($component), 'Компонент создан.', 201);
    }

    /** @OA\Get(path="/components/{id}", summary="Получить компонент с кодом", tags={"Components"}, security={{"bearerAuth":{}}}, @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")), @OA\Response(response=200, description="Данные компонента с кодом")) */
    public function show(int $id): JsonResponse
    {
        $component = Component::findOrFail($id);
        $path = storage_path('cms/components/' . basename($component->slug));

        $templateCode = '';
        $styleCode = '';
        $scriptCode = '';

        if (is_dir($path)) {
            if (file_exists($path . '/index.blade.php')) {
                $templateCode = file_get_contents($path . '/index.blade.php');
            }
            if (file_exists($path . '/style.scss')) {
                $styleCode = file_get_contents($path . '/style.scss');
            } elseif (file_exists($path . '/style.css')) {
                $styleCode = file_get_contents($path . '/style.css');
            }
            if (file_exists($path . '/script.js')) {
                $scriptCode = file_get_contents($path . '/script.js');
            }
        }

        $data = (new ComponentResource($component))->toArray(request());
        $data['template_code'] = $templateCode;
        $data['style_code'] = $styleCode;
        $data['script_code'] = $scriptCode;

        return $this->success($data);
    }

    /** @OA\Put(path="/components/{id}", summary="Обновить компонент (метаданные)", tags={"Components"}, security={{"bearerAuth":{}}}, @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")), @OA\Response(response=200, description="Обновлено")) */
    public function update(Request $request, int $id): JsonResponse
    {
        $component = Component::findOrFail($id);
        $data = $request->validate([
            'name' => 'sometimes|string|max:255', 'slug' => 'sometimes|string|max:255|unique:components,slug,' . $id,
            'params' => 'nullable|array', 'description' => 'nullable|string',
        ]);
        $component->update($data);

        $this->logAction('update', 'component', $component->id, ['name' => $component->name]);

        return $this->success(new ComponentResource($component->fresh()));
    }

    /** @OA\Delete(path="/components/{id}", summary="Удалить компонент", tags={"Components"}, security={{"bearerAuth":{}}}, @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")), @OA\Response(response=200, description="Удалено")) */
    public function destroy(int $id): JsonResponse
    {
        $component = Component::findOrFail($id);
        $name = $component->name;
        $component->delete();

        $this->logAction('delete', 'component', $id, ['name' => $name]);

        return $this->success(null, 'Компонент удалён.');
    }

    /** @OA\Get(path="/components/{id}/preview", summary="Превью компонента (HTML)", tags={"Components"}, security={{"bearerAuth":{}}}, @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")), @OA\Parameter(name="template_id", in="query", @OA\Schema(type="integer")), @OA\Response(response=200, description="HTML-превью компонента")) */
    public function preview(Request $request, int $id): JsonResponse
    {
        $component = Component::findOrFail($id);
        $path = storage_path('cms/components/' . basename($component->slug));

        // Load global fields for components that rely on $global (e.g. header)
        $global = app()->bound('global_fields') ? app('global_fields') : [];
        view()->share('global', $global);

        $templateHtml = '';
        $css = '';
        $js = '';

        $templateFile = $path . '/index.blade.php';
        if (file_exists($templateFile)) {
            try {
                $params = [];
                foreach (($component->params ?? []) as $param) {
                    $key = $param['key'] ?? '';
                    if ($key !== '') {
                        $params[$key] = $param['default'] ?? '';
                    }
                }

                $templateContent = file_get_contents($templateFile);

                // Валидация шаблона компонента при рендере (защита от подмены файлов)
                \Templite\Cms\Services\BladeSecurityValidator::assertSafe($templateContent);

                $templateHtml = Blade::render($templateContent, array_merge($params, [
                    'params' => $params,
                    'component' => $component,
                    'attributes' => new \Illuminate\View\ComponentAttributeBag(),
                    'slot' => '',
                ]));
            } catch (\Throwable $e) {
                $templateHtml = '<div style="color:#ef4444;padding:16px;font-family:monospace;font-size:13px">'
                    . '<strong>Template Error:</strong><br>'
                    . htmlspecialchars($e->getMessage())
                    . '</div>';
            }
        } else {
            $templateHtml = '<div style="color:#94a3b8;padding:32px;text-align:center;font-family:sans-serif;font-size:14px">'
                . 'Код компонента не найден. Сохраните код через редактор.'
                . '</div>';
        }

        $styleFile = $path . '/style.scss';
        if (file_exists($styleFile)) {
            try {
                $compiler = new \ScssPhp\ScssPhp\Compiler();
                $css = $compiler->compileString(file_get_contents($styleFile))->getCss();
            } catch (\Throwable $e) {
                $css = "/* SCSS Error: " . addslashes($e->getMessage()) . " */";
            }
        }

        $scriptFile = $path . '/script.js';
        if (file_exists($scriptFile)) {
            $js = file_get_contents($scriptFile);
        }

        // Template CSS/JS (global styles from page template)
        $templateCss = '';
        $templateJs = '';
        $cdnCssLinks = '';
        $cdnJsLinks = '';
        $localLibCss = '';
        $localLibJs = '';
        $templateId = $request->query('template_id');
        if ($templateId) {
            $template = TemplatePage::with('libraries')->find($templateId);
            if ($template) {
                $blockRenderer = app(BlockRenderer::class);
                $templateCss = $blockRenderer->compileTemplateStyles($template) ?? '';
                $templateJs = $blockRenderer->getTemplateScript($template) ?? '';

                // Подключаем библиотеки шаблона
                foreach ($template->libraries->where('active', true)->sortBy('sort_order') as $lib) {
                    if ($lib->load_strategy === 'cdn') {
                        if ($lib->css_cdn) {
                            $cdnCssLinks .= '<link rel="stylesheet" href="' . htmlspecialchars($lib->css_cdn) . '">' . "\n";
                        }
                        if ($lib->js_cdn) {
                            $cdnJsLinks .= '<script src="' . htmlspecialchars($lib->js_cdn) . '"></script>' . "\n";
                        }
                    } elseif ($lib->load_strategy === 'local') {
                        if ($lib->css_file) {
                            $libPath = \Illuminate\Support\Facades\Storage::disk('public')->path($lib->css_file);
                            if (file_exists($libPath)) {
                                $localLibCss .= "/* Library: {$lib->name} */\n" . file_get_contents($libPath) . "\n";
                            }
                        }
                        if ($lib->js_file) {
                            $libPath = \Illuminate\Support\Facades\Storage::disk('public')->path($lib->js_file);
                            if (file_exists($libPath)) {
                                $localLibJs .= "/* Library: {$lib->name} */\n" . file_get_contents($libPath) . "\n";
                            }
                        }
                    }
                }
            }
        }

        $allCss = $localLibCss . ($templateCss ? "{$templateCss}\n" : '') . $css;
        $allJs = $localLibJs . ($templateJs ? "{$templateJs}\n" : '') . $js;

        $componentSlug = e($component->slug);
        $wrappedContent = '<div class="cms-component cms-component--' . $componentSlug . '">' . $templateHtml . '</div>';

        $html = app(BlockRenderer::class)->renderPreviewWrapper([
            'cdnCss' => $cdnCssLinks,
            'css' => $allCss,
            'content' => $wrappedContent,
            'cdnJs' => $cdnJsLinks,
            'js' => $allJs,
        ]);

        return $this->success(['html' => $html]);
    }
}
