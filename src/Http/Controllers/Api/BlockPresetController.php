<?php

namespace Templite\Cms\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Templite\Cms\Models\Block;
use Templite\Cms\Models\BlockPreset;
use Templite\Cms\Models\TemplatePage;
use Templite\Cms\Services\BlockDataResolver;
use Templite\Cms\Services\BlockRenderer;

class BlockPresetController extends Controller
{
    public function __construct(
        protected BlockRenderer $blockRenderer,
        protected BlockDataResolver $blockDataResolver,
    ) {}

    /**
     * @OA\Get(
     *     path="/block-presets",
     *     summary="Список пресетов блоков",
     *     tags={"Block Presets"},
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(name="block_id", in="query", @OA\Schema(type="integer")),
     *     @OA\Parameter(name="type", in="query", @OA\Schema(type="string", enum={"global","local"})),
     *     @OA\Parameter(name="block_type_id", in="query", @OA\Schema(type="integer")),
     *     @OA\Parameter(name="page", in="query", @OA\Schema(type="integer")),
     *     @OA\Parameter(name="per_page", in="query", @OA\Schema(type="integer")),
     *     @OA\Response(response=200, description="Список пресетов")
     * )
     */
    public function index(Request $request): JsonResponse
    {
        $query = BlockPreset::with(['block:id,name,slug,block_type_id', 'screenFile']);

        if ($request->has('block_id')) {
            $query->where('block_id', $request->block_id);
        }

        if ($request->has('type')) {
            $query->where('type', $request->type);
        }

        if ($request->has('block_type_id')) {
            $query->whereHas('block', function ($q) use ($request) {
                $q->where('block_type_id', $request->block_type_id);
            });
        }

        $perPage = min((int) $request->input('per_page', 20), 100);
        $presets = $query->orderBy('order')->paginate($perPage);

        return $this->success($presets);
    }

    /**
     * @OA\Post(
     *     path="/block-presets",
     *     summary="Создать пресет блока",
     *     tags={"Block Presets"},
     *     security={{"bearerAuth":{}}},
     *     @OA\RequestBody(required=true, @OA\JsonContent(
     *         required={"name","slug","block_id","type"},
     *         @OA\Property(property="name", type="string"),
     *         @OA\Property(property="slug", type="string"),
     *         @OA\Property(property="block_id", type="integer"),
     *         @OA\Property(property="type", type="string", enum={"global","local"}),
     *         @OA\Property(property="data", type="object"),
     *         @OA\Property(property="screen", type="integer"),
     *         @OA\Property(property="description", type="string"),
     *         @OA\Property(property="order", type="integer")
     *     )),
     *     @OA\Response(response=201, description="Пресет создан")
     * )
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'slug' => 'required|string|max:255|unique:block_presets',
            'block_id' => 'required|integer|exists:blocks,id',
            'type' => 'required|string|in:global,local',
            'data' => 'nullable|array',
            'screen' => 'nullable|integer|exists:files,id',
            'description' => 'nullable|string|max:1000',
            'order' => 'nullable|integer',
        ]);

        $preset = BlockPreset::create($data);

        $this->logAction('create', 'block_preset', $preset->id, [
            'block_id' => $data['block_id'],
            'type' => $data['type'],
        ]);

        return $this->success(
            $preset->load(['block:id,name,slug,block_type_id', 'screenFile']),
            'Пресет создан.',
            201
        );
    }

    /**
     * @OA\Get(
     *     path="/block-presets/{id}",
     *     summary="Получить пресет блока",
     *     tags={"Block Presets"},
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
     *     @OA\Response(response=200, description="Данные пресета")
     * )
     */
    public function show(int $id): JsonResponse
    {
        $preset = BlockPreset::with([
            'block.rootFields' => fn($q) => $q->orderBy('order'),
            'block.rootFields.children' => fn($q) => $q->orderBy('order'),
            'block.rootFields.children.children' => fn($q) => $q->orderBy('order'),
            'block.tabs' => fn($q) => $q->orderBy('order'),
            'block.sections' => fn($q) => $q->orderBy('order'),
            'screenFile',
        ])->findOrFail($id);

        return $this->success($preset);
    }

    /**
     * @OA\Put(
     *     path="/block-presets/{id}",
     *     summary="Обновить пресет блока",
     *     tags={"Block Presets"},
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
     *     @OA\RequestBody(required=true, @OA\JsonContent(
     *         @OA\Property(property="name", type="string"),
     *         @OA\Property(property="slug", type="string"),
     *         @OA\Property(property="description", type="string"),
     *         @OA\Property(property="data", type="object"),
     *         @OA\Property(property="screen", type="integer"),
     *         @OA\Property(property="order", type="integer")
     *     )),
     *     @OA\Response(response=200, description="Пресет обновлён")
     * )
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $preset = BlockPreset::findOrFail($id);

        $data = $request->validate([
            'name' => 'sometimes|string|max:255',
            'slug' => 'sometimes|string|max:255|unique:block_presets,slug,' . $id,
            'description' => 'nullable|string|max:1000',
            'data' => 'nullable|array',
            'screen' => 'nullable|integer|exists:files,id',
            'order' => 'nullable|integer',
        ]);

        $preset->update($data);

        $this->logAction('update', 'block_preset', $preset->id);

        return $this->success(
            $preset->fresh(['block:id,name,slug,block_type_id', 'screenFile']),
            'Пресет обновлён.'
        );
    }

    /**
     * @OA\Delete(
     *     path="/block-presets/{id}",
     *     summary="Удалить пресет блока",
     *     tags={"Block Presets"},
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
     *     @OA\Response(response=200, description="Пресет удалён"),
     *     @OA\Response(response=422, description="Невозможно удалить — есть связанные блоки на страницах")
     * )
     */
    public function destroy(int $id): JsonResponse
    {
        $preset = BlockPreset::findOrFail($id);

        if ($preset->type === 'global') {
            $usageCount = $preset->pageBlocks()->count();
            if ($usageCount > 0) {
                return $this->error(
                    "Невозможно удалить глобальный пресет: он используется на {$usageCount} страниц(е/ах). Сначала открепите пресет от всех страниц.",
                    422
                );
            }
        }

        $preset->delete();

        $this->logAction('delete', 'block_preset', $id);

        return $this->success(null, 'Пресет удалён.');
    }

    /**
     * @OA\Post(
     *     path="/block-presets/{id}/preview",
     *     summary="Превью пресета блока (HTML)",
     *     tags={"Block Presets"},
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
     *     @OA\RequestBody(@OA\JsonContent(
     *         @OA\Property(property="data", type="object"),
     *         @OA\Property(property="template_id", type="integer")
     *     )),
     *     @OA\Response(response=200, description="HTML-превью пресета")
     * )
     */
    public function preview(Request $request, int $id): JsonResponse
    {
        $preset = BlockPreset::with([
            'block.fields.children',
            'block.blockActions.action',
            'block.libraries',
        ])->findOrFail($id);

        $block = $preset->block;

        // Load global fields for components that rely on $global (e.g. header)
        $global = app()->bound('global_fields') ? app('global_fields') : [];
        view()->share('global', $global);

        // Use request data if provided, otherwise use preset data
        $rawData = $request->has('data')
            ? $request->input('data', [])
            : ($preset->data ?? []);

        $resolvedData = $this->blockDataResolver->resolveBlockData($block, $rawData);

        // У пресета нет страницы — actions не выполняем
        $actions = [];

        // Inline-код из запроса (для live preview из редактора блока)
        $inlineTemplate = $request->input('template');
        $inlineStyle = $request->input('style');
        $inlineScript = $request->input('script');

        $blockHtml = '';
        $css = '';
        $js = '';
        $blockPath = $this->blockRenderer->resolveBlockPath($block);

        // Template: inline или с диска
        if ($inlineTemplate !== null) {
            try {
                // Валидация inline-шаблона перед рендером
                \Templite\Cms\Services\BladeSecurityValidator::assertSafe($inlineTemplate);

                $html = \Illuminate\Support\Facades\Blade::render($inlineTemplate, [
                    'fields'  => \Templite\Cms\Support\FieldsBag::wrap($resolvedData),
                    'actions' => $actions,
                    'page'    => null,
                    'global'  => $global,
                    'block'   => $block,
                ]);

                if ($block->no_wrapper) {
                    $blockHtml = $html;
                } else {
                    $blockId = uniqid($block->slug . '-');
                    $blockHtml = "<div class=\"cms-block cms-block--{$block->slug}\" data-block=\"{$block->slug}\" data-block-id=\"{$blockId}\">\n{$html}\n</div>";
                }
            } catch (\Throwable $e) {
                $blockHtml = '<div style="color:#ef4444;padding:16px;font-family:monospace;font-size:13px">'
                    . '<strong>Template Error:</strong><br>'
                    . htmlspecialchars($e->getMessage())
                    . '</div>';
            }
        } elseif ($blockPath) {
            try {
                $blockHtml = $this->blockRenderer->render($block, $resolvedData, $actions, null, $global);
            } catch (\Throwable $e) {
                $blockHtml = '<div style="color:#ef4444;padding:16px;font-family:monospace;font-size:13px">'
                    . '<strong>Template Error:</strong><br>'
                    . htmlspecialchars($e->getMessage())
                    . '</div>';
            }
        } else {
            $blockHtml = '<div style="color:#94a3b8;padding:32px;text-align:center;font-family:sans-serif;font-size:14px">'
                . 'Код блока не найден. Сохраните код через редактор.'
                . '</div>';
        }

        // Style: inline или с диска
        if ($inlineStyle !== null) {
            $css = $this->blockRenderer->compileStylesFromString($inlineStyle, $block->slug, (bool) $block->no_wrapper) ?? '';
        } elseif ($blockPath) {
            $css = $this->blockRenderer->compileStyles($block) ?? '';
        }

        // Script: inline или с диска
        if ($inlineScript !== null) {
            $js = $inlineScript;
        } elseif ($blockPath) {
            $scriptFile = $blockPath . '/script.js';
            if (file_exists($scriptFile)) {
                $js = file_get_contents($scriptFile);
            }
        }

        // Template CSS/JS
        $templateCss = '';
        $templateJs = '';
        $cdnCssLinks = '';
        $cdnJsLinks = '';
        $templateId = $request->input('template_id');
        if ($templateId) {
            $template = TemplatePage::with('libraries')->find($templateId);
            if ($template) {
                $templateCss = $this->blockRenderer->compileTemplateStyles($template) ?? '';
                $templateJs = $this->blockRenderer->getTemplateScript($template) ?? '';

                foreach ($template->libraries->where('active', true)->sortBy('sort_order') as $lib) {
                    if ($lib->load_strategy === 'cdn') {
                        if ($lib->css_cdn) {
                            $cdnCssLinks .= '<link rel="stylesheet" href="' . e($lib->css_cdn) . '">' . "\n";
                        }
                        if ($lib->js_cdn) {
                            $cdnJsLinks .= '<script src="' . e($lib->js_cdn) . '"></script>' . "\n";
                        }
                    } elseif ($lib->load_strategy === 'local') {
                        if ($lib->css_file) {
                            $cdnCssLinks .= '<link rel="stylesheet" href="' . e(asset('storage/' . ltrim($lib->css_file, '/'))) . '">' . "\n";
                        }
                        if ($lib->js_file) {
                            $cdnJsLinks .= '<script src="' . e(asset('storage/' . ltrim($lib->js_file, '/'))) . '"></script>' . "\n";
                        }
                    }
                }
            }
        }

        // Block libraries
        foreach ($block->libraries->where('active', true)->sortBy('sort_order') as $lib) {
            if ($lib->load_strategy === 'cdn') {
                if ($lib->css_cdn) {
                    $cdnCssLinks .= '<link rel="stylesheet" href="' . e($lib->css_cdn) . '">' . "\n";
                }
                if ($lib->js_cdn) {
                    $cdnJsLinks .= '<script src="' . e($lib->js_cdn) . '"></script>' . "\n";
                }
            } elseif ($lib->load_strategy === 'local') {
                if ($lib->css_file) {
                    $cdnCssLinks .= '<link rel="stylesheet" href="' . e(asset('storage/' . ltrim($lib->css_file, '/'))) . '">' . "\n";
                }
                if ($lib->js_file) {
                    $cdnJsLinks .= '<script src="' . e(asset('storage/' . ltrim($lib->js_file, '/'))) . '"></script>' . "\n";
                }
            }
        }

        $allCss = implode("\n", array_filter([$templateCss, $css]));
        $allJs = implode("\n", array_filter([$templateJs, $js]));

        $html = $this->blockRenderer->renderPreviewWrapper([
            'cdnCss' => $cdnCssLinks,
            'css' => $allCss,
            'content' => $blockHtml,
            'cdnJs' => $cdnJsLinks,
            'js' => $allJs,
        ]);

        return $this->success(['html' => $html]);
    }

    /**
     * @OA\Get(
     *     path="/blocks/{blockId}/presets",
     *     summary="Пресеты конкретного блока",
     *     tags={"Block Presets"},
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(name="blockId", in="path", required=true, @OA\Schema(type="integer")),
     *     @OA\Response(response=200, description="Список пресетов блока")
     * )
     */
    public function forBlock(int $blockId): JsonResponse
    {
        Block::findOrFail($blockId);

        $presets = BlockPreset::forBlock($blockId)
            ->with('screenFile')
            ->orderBy('order')
            ->get();

        return $this->success($presets);
    }
}
