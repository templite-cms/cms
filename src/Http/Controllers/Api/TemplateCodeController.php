<?php

namespace Templite\Cms\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Blade;
use Templite\Cms\Models\TemplatePage;
use Templite\Cms\Services\BladeSecurityValidator;
use Templite\Cms\Services\BlockRenderer;
use Templite\Cms\Services\PageAssetCompiler;

class TemplateCodeController extends Controller
{
    public function __construct(
        protected BlockRenderer $blockRenderer,
    ) {}
    /** @OA\Get(path="/templates/{id}/code", summary="Получить Blade/CSS/JS код шаблона", tags={"Template Code"}, security={{"bearerAuth":{}}}, @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")), @OA\Response(response=200, description="Код шаблона")) */
    public function show(int $id): JsonResponse
    {
        $template = TemplatePage::findOrFail($id);
        $path = storage_path('cms/templates/' . basename($template->slug));

        $code = ['template' => '', 'style' => '', 'script' => ''];

        if (is_dir($path)) {
            if (file_exists($path . '/template.blade.php')) {
                $code['template'] = file_get_contents($path . '/template.blade.php');
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

    /** @OA\Put(path="/templates/{id}/code", summary="Сохранить Blade/CSS/JS код шаблона", tags={"Template Code"}, security={{"bearerAuth":{}}}, @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")), @OA\RequestBody(required=true, @OA\JsonContent(@OA\Property(property="template", type="string"), @OA\Property(property="style", type="string"), @OA\Property(property="script", type="string"))), @OA\Response(response=200, description="Сохранено")) */
    public function update(Request $request, int $id): JsonResponse
    {
        $template = TemplatePage::findOrFail($id);
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

        $path = storage_path('cms/templates/' . basename($template->slug));
        if (!is_dir($path)) {
            mkdir($path, 0755, true);
        }

        if (array_key_exists('template', $data)) {
            file_put_contents($path . '/template.blade.php', $data['template'] ?? '');
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

        app(PageAssetCompiler::class)->recompileForTemplate($template->id);

        $this->logAction('update_code', 'template', $template->id, ['name' => $template->name]);

        return $this->success(null, 'Код шаблона сохранён.');
    }

    /** @OA\Get(path="/templates/{id}/preview", summary="Превью шаблона", tags={"Template Code"}, security={{"bearerAuth":{}}}, @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")), @OA\Response(response=200, description="HTML превью")) */
    public function preview(Request $request, int $id): JsonResponse
    {
        $template = TemplatePage::with(['fields.children', 'libraries'])->findOrFail($id);
        $path = storage_path('cms/templates/' . basename($template->slug));

        // Load global fields for components/templates that rely on $global
        $global = app()->bound('global_fields') ? app('global_fields') : [];
        view()->share('global', $global);

        // Inline-код из запроса (для live preview без сохранения)
        $inlineTemplate = $request->input('template_code');
        $inlineStyle = $request->input('style_code');
        $inlineScript = $request->input('script_code');

        $templateHtml = '';

        // Собираем default-данные из определений полей
        $fields = [];
        foreach ($template->fields as $field) {
            if ($field->parent_id) continue;

            if ($field->type === 'array') {
                $children = $template->fields->where('parent_id', $field->id);
                $row = [];
                foreach ($children as $child) {
                    $row[$child->key] = $child->default_value ?? $this->mockValue($child);
                }
                $fields[$field->key] = [$row];
            } else {
                $fields[$field->key] = $field->default_value ?? $this->mockValue($field);
            }
        }

        $renderVars = [
            'fields' => $fields,
            'page' => null,
            'global' => $global,
        ];

        // Template: inline или с диска
        if ($inlineTemplate !== null) {
            try {
                \Templite\Cms\Services\BladeSecurityValidator::assertSafe($inlineTemplate);
                $templateHtml = Blade::render($inlineTemplate, $renderVars);
            } catch (\Throwable $e) {
                $templateHtml = '<div style="color:#ef4444;padding:16px;font-family:monospace;font-size:13px">'
                    . '<strong>Template Error:</strong><br>'
                    . htmlspecialchars($e->getMessage())
                    . '</div>';
            }
        } elseif (is_dir($path)) {
            $templateFile = $path . '/template.blade.php';
            if (file_exists($templateFile)) {
                try {
                    $bladeContent = file_get_contents($templateFile);
                    \Templite\Cms\Services\BladeSecurityValidator::assertSafe($bladeContent);
                    $templateHtml = Blade::render($bladeContent, $renderVars);
                } catch (\Throwable $e) {
                    $templateHtml = '<div style="color:#ef4444;padding:16px;font-family:monospace;font-size:13px">'
                        . '<strong>Template Error:</strong><br>'
                        . htmlspecialchars($e->getMessage())
                        . '</div>';
                }
            }
        }

        // Style: inline или с диска
        if ($inlineStyle !== null) {
            try {
                $compiler = new \ScssPhp\ScssPhp\Compiler();
                $css = $compiler->compileString($inlineStyle)->getCss();
            } catch (\Throwable $e) {
                $css = "/* SCSS Error: " . addslashes($e->getMessage()) . " */";
            }
        } else {
            $css = $this->blockRenderer->compileTemplateStyles($template) ?? '';
        }

        // Script: inline или с диска
        if ($inlineScript !== null) {
            $js = $inlineScript;
        } else {
            $js = $this->blockRenderer->getTemplateScript($template) ?? '';
        }

        // Load template libraries (CDN and local)
        $cdnCssLinks = '';
        $cdnJsLinks = '';
        $localLibCss = '';
        $localLibJs = '';

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

        // Component styles/scripts from template content
        $rawTemplate = $inlineTemplate ?? '';
        if (!$rawTemplate) {
            $tplFile = $path . '/template.blade.php';
            if (file_exists($tplFile)) {
                $rawTemplate = file_get_contents($tplFile);
            }
        }
        $componentAssets = $this->blockRenderer->collectComponentAssets($rawTemplate);

        $allCss = $localLibCss . ($css ? "{$css}\n" : '') . $componentAssets['css'];
        $allJs = $localLibJs . ($js ? "{$js}\n" : '') . $componentAssets['js'];

        $html = $this->blockRenderer->renderPreviewWrapper([
            'cdnCss' => $cdnCssLinks,
            'css' => $allCss,
            'content' => $templateHtml,
            'cdnJs' => $cdnJsLinks,
            'js' => $allJs,
        ]);

        return $this->success(['html' => $html]);
    }

    private function mockValue(object $field): string
    {
        return match ($field->type) {
            'number' => '0',
            'checkbox' => '',
            'img', 'file' => '',
            'editor', 'html' => '<p>[' . $field->name . ']</p>',
            'date' => now()->format('Y-m-d'),
            'datetime' => now()->format('Y-m-d H:i'),
            'color' => '#cccccc',
            'link' => '#',
            default => '[' . $field->name . ']',
        };
    }
}
