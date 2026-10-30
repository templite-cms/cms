<?php

namespace Templite\Cms\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Templite\Cms\Http\Resources\BlockResource;
use Templite\Cms\Models\Block;
use Templite\Cms\Models\BlockPreset;
use Templite\Cms\Models\TemplatePage;
use Templite\Cms\Services\BlockDataResolver;
use Templite\Cms\Services\BlockRenderer;
use Templite\Cms\Services\FileService;
use Templite\Cms\Services\LibraryAssetBuilder;
use Templite\Cms\Helpers\StringHelper;
use Templite\Cms\Services\ImageProcessor;

class BlockController extends Controller
{
    public function __construct(
        protected BlockRenderer $blockRenderer,
        protected BlockDataResolver $blockDataResolver,
        protected FileService $fileService,
        protected ImageProcessor $imageProcessor,
    ) {}

    /** @OA\Get(path="/blocks", summary="Список блоков", tags={"Blocks"}, security={{"bearerAuth":{}}}, @OA\Parameter(name="block_type_id", in="query", @OA\Schema(type="integer")), @OA\Parameter(name="search", in="query", @OA\Schema(type="string")), @OA\Response(response=200, description="Список")) */
    public function index(Request $request): JsonResponse
    {
        $query = Block::with(['blockType', 'screenshot']);
        if ($request->has('block_type_id')) $query->where('block_type_id', $request->block_type_id);
        if ($request->has('search')) $query->where('name', 'like', '%' . StringHelper::escapeLike($request->search) . '%');
        return $this->success(BlockResource::collection($query->orderBy('order')->get()));
    }

    /** @OA\Post(path="/blocks", summary="Создать блок", tags={"Blocks"}, security={{"bearerAuth":{}}}, @OA\RequestBody(required=true, @OA\JsonContent(required={"name","slug","block_type_id"}, @OA\Property(property="name", type="string"), @OA\Property(property="slug", type="string"), @OA\Property(property="block_type_id", type="integer"))), @OA\Response(response=201, description="Создано")) */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'slug' => 'required|string|max:255|unique:blocks',
            'block_type_id' => 'required|integer|exists:block_types,id',
            'source' => 'string|in:database,file,vendor',
            'path' => 'nullable|string',
            'tags' => 'nullable|string',
            'order' => 'integer',
            'auto_marker' => 'boolean',
        ]);
        $block = Block::create($data);
        return $this->success(new BlockResource($block->load('blockType')), 'Блок создан.', 201);
    }

    /**
     * BF-017: GET /api/cms/blocks/{id} -- блок с деревом полей, вкладками и секциями.
     *
     * @OA\Get(path="/blocks/{id}", summary="Получить блок", tags={"Blocks"}, security={{"bearerAuth":{}}}, @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")), @OA\Response(response=200, description="Данные блока"))
     */
    public function show(int $id): JsonResponse
    {
        $block = Block::with([
            'blockType',
            'rootFields' => fn($q) => $q->orderBy('order'),
            'rootFields.children' => fn($q) => $q->orderBy('order'),
            'rootFields.children.children' => fn($q) => $q->orderBy('order'),
            'blockActions.action',
            'screenshot',
            'libraries',
        ])->findOrFail($id);

        return $this->success(new BlockResource($block));
    }

    /** @OA\Put(path="/blocks/{id}", summary="Обновить блок", tags={"Blocks"}, security={{"bearerAuth":{}}}, @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")), @OA\Response(response=200, description="Обновлено")) */
    public function update(Request $request, int $id): JsonResponse
    {
        $block = Block::findOrFail($id);
        $data = $request->validate([
            'name' => 'sometimes|string|max:255',
            'slug' => 'sometimes|string|max:255|unique:blocks,slug,' . $id,
            'block_type_id' => 'sometimes|integer|exists:block_types,id',
            'source' => 'string|in:database,file,vendor',
            'path' => 'nullable|string',
            'tags' => 'nullable|string',
            'screen' => 'nullable|integer|exists:files,id',
            'order' => 'integer',
            'auto_marker' => 'boolean',
            'library_ids' => 'nullable|array',
            'library_ids.*' => 'integer|exists:libraries,id',
        ]);
        $block->update($data);

        if ($request->has('library_ids')) {
            $block->libraries()->sync($request->input('library_ids', []));
        }

        return $this->success(new BlockResource($block->fresh([
            'blockType',
            'rootFields' => fn($q) => $q->orderBy('order'),
            'rootFields.children' => fn($q) => $q->orderBy('order'),
            'rootFields.children.children' => fn($q) => $q->orderBy('order'),
            'libraries',
        ])));
    }

    /** @OA\Delete(path="/blocks/{id}", summary="Удалить блок", tags={"Blocks"}, security={{"bearerAuth":{}}}, @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")), @OA\Response(response=200, description="Удалено")) */
    public function destroy(int $id): JsonResponse
    {
        Block::findOrFail($id)->delete();
        return $this->success(null, 'Блок удалён.');
    }

    /** @OA\Post(path="/blocks/{id}/copy", summary="Копировать блок", tags={"Blocks"}, security={{"bearerAuth":{}}}, @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")), @OA\Response(response=201, description="Копия создана")) */
    public function copy(int $id): JsonResponse
    {
        $block = Block::findOrFail($id);
        $newBlock = $block->replicate();
        $newBlock->name = $block->name . ' (копия)';
        $newBlock->slug = $block->slug . '-copy-' . time();
        $newBlock->save();

        $newBlock->importFields($block->exportFields());

        return $this->success(new BlockResource($newBlock->load(['blockType', 'fields'])), 'Блок скопирован.', 201);
    }

    /** @OA\Get(path="/blocks/{id}/preview", summary="Превью блока (HTML)", tags={"Blocks"}, security={{"bearerAuth":{}}}, @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")), @OA\Parameter(name="template_id", in="query", @OA\Schema(type="integer")), @OA\Response(response=200, description="HTML-превью блока")) */
    public function preview(Request $request, int $id): JsonResponse
    {
        $block = Block::with(['fields', 'blockActions.action'])->findOrFail($id);
        $path = $this->blockRenderer->resolveBlockPath($block);

        // Load global fields for components that rely on $global (e.g. header)
        $global = app()->bound('global_fields') ? app('global_fields') : [];
        view()->share('global', $global);

        // Inline-код из запроса (для live preview без сохранения)
        $inlineTemplate = $request->input('template');
        $inlineStyle = $request->input('style');
        $inlineScript = $request->input('script');

        $blockHtml = '';
        $css = '';
        $js = '';

        // Данные превью — из выбранного пресета, иначе из пресета по умолчанию
        $presetId = $request->input('preset_id');
        $preset = $presetId
            ? BlockPreset::where('block_id', $block->id)->find($presetId)
            : BlockPreset::defaultForBlock($block->id);
        $rawData = $preset?->data ?? [];
        $fields = $this->blockDataResolver->resolveBlockData($block, $rawData);

        // Выполняем привязанные actions, чтобы $actions был доступен в превью
        // (как на реальной странице). Контекст-страница — реальная страница
        // с этим блоком, иначе пустой Page. Ошибки не ломают превью.
        $previewPage = \Templite\Cms\Models\Page::whereHas(
            'pageBlocks', fn ($q) => $q->where('block_id', $block->id)
        )->first() ?? new \Templite\Cms\Models\Page();

        try {
            $actions = app(\Templite\Cms\Services\ActionRunner::class)
                ->run($block, $fields, $previewPage, $request, $global, []);
        } catch (\Throwable $e) {
            $actions = [];
        }

        // Template: inline или с диска
        if ($inlineTemplate !== null) {
            try {
                // Валидация inline-шаблона перед рендером
                \Templite\Cms\Services\BladeSecurityValidator::assertSafe($inlineTemplate);

                $html = \Illuminate\Support\Facades\Blade::render($inlineTemplate, [
                    'fields'  => \Templite\Cms\Support\FieldsBag::wrap($fields),
                    'actions' => $actions,
                    'page'    => null,
                    'global'  => $global,
                    'block'   => $block,
                ]);

                $blockHtml = $this->blockRenderer->applyMarker($html, $block);
            } catch (\Throwable $e) {
                $blockHtml = '<div style="color:#ef4444;padding:16px;font-family:monospace;font-size:13px">'
                    . '<strong>Template Error:</strong><br>'
                    . htmlspecialchars($e->getMessage())
                    . '</div>';
            }
        } elseif ($path) {
            $templateFile = $path . '/template.blade.php';
            if (file_exists($templateFile)) {
                try {
                    $blockHtml = $this->blockRenderer->render($block, $fields, $actions, null, $global);
                } catch (\Throwable $e) {
                    $blockHtml = '<div style="color:#ef4444;padding:16px;font-family:monospace;font-size:13px">'
                        . '<strong>Template Error:</strong><br>'
                        . htmlspecialchars($e->getMessage())
                        . '</div>';
                }
            }
        } else {
            $blockHtml = '<div style="color:#94a3b8;padding:32px;text-align:center;font-family:sans-serif;font-size:14px">'
                . 'Код блока не найден. Сохраните код через редактор.'
                . '</div>';
        }

        // Style: inline или с диска
        if ($inlineStyle !== null) {
            $compiledCss = $this->blockRenderer->compileStylesFromString($inlineStyle, $block->slug);
            if ($compiledCss) {
                $css = $compiledCss;
            }
        } elseif ($path) {
            $compiledCss = $this->blockRenderer->compileStyles($block);
            if ($compiledCss) {
                $css = $compiledCss;
            }
        }

        // Script: inline или с диска
        if ($inlineScript !== null) {
            $js = $inlineScript;
        } elseif ($path) {
            $scriptFile = $path . '/script.js';
            if (file_exists($scriptFile)) {
                $js = file_get_contents($scriptFile);
            }
        }

        // Template CSS/JS (global styles from page template)
        $templateCss = '';
        $templateJs = '';
        $previewLibraries = collect();
        $templateId = $request->query('template_id');
        if ($templateId) {
            $template = TemplatePage::with('libraries.files')->find($templateId);
            if ($template) {
                $templateCss = $this->blockRenderer->compileTemplateStyles($template) ?? '';
                $templateJs = $this->blockRenderer->getTemplateScript($template) ?? '';

                // Библиотеки шаблона
                $previewLibraries = $previewLibraries->merge($template->libraries);
            }
        }

        // Библиотеки самого блока — в ту же коллекцию: билдер дедуплицирует,
        // и общая с шаблоном библиотека подключится один раз.
        $block->loadMissing('libraries.files');
        $previewLibraries = $previewLibraries->merge($block->libraries);

        $libraryHtml = app(LibraryAssetBuilder::class)->htmlFor($previewLibraries);
        $cdnCssLinks = $libraryHtml['head'];
        $cdnJsLinks = $libraryHtml['body'];

        // Component styles/scripts from block template content
        $rawTemplate = $inlineTemplate ?? '';
        if (!$rawTemplate && $path && file_exists($path . '/template.blade.php')) {
            $rawTemplate = file_get_contents($path . '/template.blade.php');
        }
        $componentAssets = $this->blockRenderer->collectComponentAssets($rawTemplate);

        $allCss = ($templateCss ? "{$templateCss}\n" : '') . $componentAssets['css'] . $css;
        // Тот же рантайм, что на сайте: скрипт блока получает el и ждёт готовности DOM
        $allJs = $this->blockRenderer->previewScript(
            [[$block, $js]],
            ($templateJs ? "{$templateJs}\n" : '') . $componentAssets['js']
        );

        $html = $this->blockRenderer->renderPreviewWrapper([
            'cdnCss' => $cdnCssLinks,
            'css' => $allCss,
            'content' => $blockHtml,
            'cdnJs' => $cdnJsLinks,
            'js' => $allJs,
        ]);

        return $this->success(['html' => $html]);
    }

    /** @OA\Post(path="/blocks/{id}/screenshot", summary="Загрузить скриншот блока", tags={"Blocks"}, security={{"bearerAuth":{}}}, @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")), @OA\RequestBody(required=true, @OA\MediaType(mediaType="multipart/form-data", @OA\Schema(@OA\Property(property="screenshot", type="string", format="binary")))), @OA\Response(response=200, description="Скриншот сохранён")) */
    public function screenshot(Request $request, int $id): JsonResponse
    {
        $block = Block::with('screenshot')->findOrFail($id);

        $request->validate([
            'screenshot' => 'required|image|max:5120|mimes:jpeg,png,webp',
        ]);

        $file = DB::transaction(function () use ($request, $block) {
            // Удаляем старый скриншот
            if ($block->screen && $block->screenshot) {
                $this->fileService->delete($block->screenshot);
            }

            // Загружаем новый файл
            $file = $this->fileService->upload($request->file('screenshot'));

            // Привязываем к блоку
            $block->update(['screen' => $file->id]);

            return $file;
        });

        // Создаём ресайзы (вне транзакции — graceful degradation)
        try {
            $this->imageProcessor->processImage($file, [
                'sizes' => config('cms.block_screenshot_sizes', [
                    'thumb' => ['width' => 300, 'height' => 200, 'fit' => 'cover'],
                    'medium' => ['width' => 600, 'height' => null, 'fit' => 'contain'],
                ]),
                'formats' => ['original', 'webp'],
                'quality' => config('cms.default_image_quality', 85),
            ]);
        } catch (\Throwable $e) {
            Log::warning("Block screenshot resize failed for file #{$file->id}: {$e->getMessage()}");
        }

        return $this->success(
            new BlockResource($block->fresh(['blockType', 'screenshot'])),
            'Скриншот сохранён.'
        );
    }
}
