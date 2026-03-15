<?php

namespace Templite\Cms\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Templite\Cms\Http\Resources\PageBlockDataResource;
use Templite\Cms\Http\Resources\PageBlockResource;
use Templite\Cms\Models\BlockPreset;
use Templite\Cms\Models\Library;
use Templite\Cms\Models\Page;
use Templite\Cms\Models\PageBlock;
use Templite\Cms\Services\ActionRunner;
use Templite\Cms\Services\BlockDataResolver;
use Templite\Cms\Services\BlockRenderer;
use Templite\Cms\Services\CacheManager;

class PageBlockController extends Controller
{
    public function __construct(
        protected CacheManager $cacheManager,
        protected BlockDataResolver $blockDataResolver,
        protected ActionRunner $actionRunner,
        protected BlockRenderer $blockRenderer,
    ) {}

    /** @OA\Get(path="/pages/{id}/blocks", summary="Блоки страницы", tags={"Page Blocks"}, security={{"bearerAuth":{}}}, @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")), @OA\Response(response=200, description="Блоки")) */
    public function index(int $id): JsonResponse
    {
        $page = Page::findOrFail($id);
        $blocks = PageBlock::where('page_id', $page->id)->with(['block.fields', 'block.blockType', 'preset'])->orderBy('order')->get();
        return $this->success(PageBlockResource::collection($blocks));
    }

    /** @OA\Get(path="/page-blocks/{id}", summary="Получить блок страницы", tags={"Page Blocks"}, security={{"bearerAuth":{}}}, @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")), @OA\Response(response=200, description="Блок страницы")) */
    public function show(int $id): JsonResponse
    {
        $pb = PageBlock::with([
            'preset',
            'block.blockType',
            'block.blockActions.action',
            'block.fields.children.children',
            'block.tabs',
            'block.sections',
        ])->findOrFail($id);

        return $this->success($pb);
    }

    /** @OA\Post(path="/pages/{id}/blocks", summary="Добавить блок на страницу", tags={"Page Blocks"}, security={{"bearerAuth":{}}}, @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")), @OA\RequestBody(required=true, @OA\JsonContent(required={"block_id"}, @OA\Property(property="block_id", type="integer"), @OA\Property(property="data", type="object"), @OA\Property(property="order", type="integer"))), @OA\Response(response=201, description="Блок добавлен")) */
    public function store(Request $request, int $id): JsonResponse
    {
        $page = Page::findOrFail($id);
        $data = $request->validate([
            'block_id' => 'required|integer|exists:blocks,id',
            'data' => 'nullable|array',
            'order' => 'integer',
            'preset_id' => 'nullable|integer|exists:block_presets,id',
        ]);

        $presetId = $data['preset_id'] ?? null;
        unset($data['preset_id']);

        $data['page_id'] = $page->id;
        if (!isset($data['order'])) {
            $data['order'] = PageBlock::where('page_id', $page->id)->max('order') + 1;
        }
        $pb = PageBlock::create($data);

        // Apply preset data if provided
        if ($presetId) {
            $preset = BlockPreset::find($presetId);
            if ($preset) {
                if ($preset->type === 'local') {
                    // Copy preset data into page block
                    $pb->update([
                        'data' => $preset->data ?? [],
                        'preset_id' => $preset->id,
                    ]);
                    // Create version with preset data
                    if (!empty($preset->data)) {
                        $pb->createVersion(
                            $preset->data,
                            null,
                            auth()->id(),
                            'preset'
                        );
                    }
                } elseif ($preset->type === 'global') {
                    // Link to global preset without copying data
                    $pb->update([
                        'preset_id' => $preset->id,
                        'data' => [],
                    ]);
                }
            }
        }

        $this->logAction('create', 'page_block', $pb->id, [
            'page_id' => $page->id,
            'block_id' => $data['block_id'],
            'preset_id' => $presetId,
        ]);

        return $this->success(new PageBlockResource($pb->load([
            'block.blockType',
            'block.blockActions.action',
            'block.fields.children',
            'block.tabs',
            'block.sections',
            'preset',
        ])), 'Блок добавлен.', 201);
    }

    /** @OA\Put(path="/page-blocks/{id}", summary="Обновить данные блока", tags={"Page Blocks"}, security={{"bearerAuth":{}}}, @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")), @OA\RequestBody(required=true, @OA\JsonContent(@OA\Property(property="data", type="object"))), @OA\Response(response=200, description="Обновлено")) */
    public function update(Request $request, int $id): JsonResponse
    {
        $pb = PageBlock::findOrFail($id);
        $data = $request->validate([
            'data' => 'nullable|array',
            'action_params' => 'nullable|array',
            'status' => 'nullable|string|in:published,draft,hidden',
            'cache_enabled' => 'nullable|boolean',
            'cache_key' => 'nullable|string|max:255',
            'preset_id' => 'nullable|integer|exists:block_presets,id',
            'field_overrides' => 'nullable|array',
        ]);
        $pb->update($data);
        $this->cacheManager->invalidateBlock($pb);

        $this->logAction('update_data', 'page_block', $pb->id, ['page_id' => $pb->page_id]);

        return $this->success(new PageBlockResource($pb->fresh(['block', 'preset'])));
    }

    /** @OA\Delete(path="/page-blocks/{id}", summary="Удалить блок со страницы", tags={"Page Blocks"}, security={{"bearerAuth":{}}}, @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")), @OA\Response(response=200, description="Удалено")) */
    public function destroy(int $id): JsonResponse
    {
        $pb = PageBlock::findOrFail($id);
        $pageId = $pb->page_id;
        $blockId = $pb->block_id;
        $this->cacheManager->invalidateBlock($pb);
        $pb->delete();

        $this->logAction('delete', 'page_block', $id, ['page_id' => $pageId, 'block_id' => $blockId]);

        return $this->success(null, 'Блок удалён со страницы.');
    }

    /** @OA\Put(path="/pages/{id}/blocks/reorder", summary="Порядок блоков", tags={"Page Blocks"}, security={{"bearerAuth":{}}}, @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")), @OA\Response(response=200, description="Порядок обновлён")) */
    public function reorder(Request $request, int $id): JsonResponse
    {
        $data = $request->validate(['items' => 'required|array', 'items.*.id' => 'required|integer', 'items.*.order' => 'required|integer']);
        foreach ($data['items'] as $item) {
            PageBlock::where('id', $item['id'])->where('page_id', $id)->update(['order' => $item['order']]);
        }

        $this->logAction('reorder', 'page_block', null, ['page_id' => $id]);

        return $this->success(null, 'Порядок обновлён.');
    }

    /** @OA\Post(path="/page-blocks/{id}/copy", summary="Копировать блок", tags={"Page Blocks"}, security={{"bearerAuth":{}}}, @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")), @OA\Response(response=201, description="Копия создана")) */
    public function copy(int $id): JsonResponse
    {
        $pb = PageBlock::findOrFail($id);
        $newPb = $pb->replicate();
        $newPb->order = PageBlock::where('page_id', $pb->page_id)->max('order') + 1;
        $newPb->cache_enabled = false;
        $newPb->save();

        $this->logAction('copy', 'page_block', $newPb->id, ['page_id' => $pb->page_id, 'source_id' => $id]);

        return $this->success(new PageBlockResource($newPb->load(['block', 'preset'])), 'Блок скопирован.', 201);
    }

    /** @OA\Put(path="/page-blocks/{id}/cache", summary="Переключить кэш блока", tags={"Page Blocks"}, security={{"bearerAuth":{}}}, @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")), @OA\Response(response=200, description="Обновлено")) */
    public function toggleCache(Request $request, int $id): JsonResponse
    {
        $pb = PageBlock::findOrFail($id);
        $data = $request->validate(['cache_enabled' => 'required|boolean', 'cache_key' => 'nullable|string']);
        $pb->update($data);
        if (!$data['cache_enabled']) {
            $this->cacheManager->invalidateBlock($pb);
        }

        $this->logAction('update', 'page_block', $pb->id, ['cache_enabled' => $data['cache_enabled']]);

        return $this->success(new PageBlockResource($pb->fresh(['preset'])));
    }

    /** @OA\Post(path="/page-blocks/{id}/preview", summary="Превью блока (HTML)", tags={"Page Blocks"}, security={{"bearerAuth":{}}}, @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")), @OA\RequestBody(@OA\JsonContent(@OA\Property(property="data", type="object"), @OA\Property(property="action_params", type="object"))), @OA\Response(response=200, description="HTML-превью блока")) */
    public function preview(Request $request, int $id): JsonResponse
    {
        $pb = PageBlock::with(['block.fields.children', 'block.blockActions.action', 'block.libraries', 'preset'])
            ->findOrFail($id);

        $page = Page::with('templatePage.libraries')->findOrFail($pb->page_id);

        // Load global fields for components that rely on $global (e.g. header)
        $global = app()->bound('global_fields') ? app('global_fields') : [];
        view()->share('global', $global);

        // If POST with data — use request data for live preview; otherwise use DB data
        if ($request->isMethod('post') && $request->has('data')) {
            $data = $request->input('data', []);

            // Merge with global preset data when applicable
            // Use field_overrides from request (current UI state) if provided, fallback to DB
            if ($pb->preset_id && $pb->preset && $pb->preset->type === 'global') {
                $presetData = $pb->preset->data ?? [];
                $overrides = $request->has('field_overrides')
                    ? ($request->input('field_overrides') ?? [])
                    : ($pb->field_overrides ?? []);
                $merged = $presetData;
                foreach ($overrides as $key => $isOverridden) {
                    if ($isOverridden && array_key_exists($key, $data)) {
                        $merged[$key] = $data[$key];
                    }
                }
                $data = $merged;
            }

            $resolvedData = $this->blockDataResolver->resolveBlockData(
                $pb->block,
                $data
            );
            $actionParams = $request->input('action_params', $pb->action_params ?? []);
        } else {
            $this->blockDataResolver->resolvePageBlocks(collect([$pb]));
            $resolvedData = $pb->resolved_data ?? $pb->data ?? [];
            $actionParams = $pb->action_params ?? [];
        }

        $actions = $this->actionRunner->run(
            $pb->block,
            $resolvedData,
            $page,
            request(),
            $global,
            $actionParams
        );

        $blockHtml = $this->blockRenderer->render(
            $pb->block,
            $resolvedData,
            $actions,
            $page,
            $global
        );

        // Block CSS/JS
        $css = $this->blockRenderer->compileStyles($pb->block) ?? '';
        $js = '';
        $blockPath = $this->blockRenderer->resolveBlockPath($pb->block);
        if ($blockPath && file_exists($blockPath . '/script.js')) {
            $js = file_get_contents($blockPath . '/script.js');
        }

        // Template CSS/JS
        $templateCss = '';
        $templateJs = '';
        if ($page->templatePage) {
            $templateCss = $this->blockRenderer->compileTemplateStyles($page->templatePage) ?? '';
            $templateJs = $this->blockRenderer->getTemplateScript($page->templatePage) ?? '';
        }

        // Libraries (CDN + local)
        $cdnHead = '';
        $cdnScripts = '';
        $libraryIds = $pb->block->libraries ? $pb->block->libraries->pluck('id') : collect();
        if ($page->templatePage && $page->templatePage->libraries) {
            $libraryIds = $libraryIds->merge($page->templatePage->libraries->pluck('id'));
        }
        if ($libraryIds->isNotEmpty()) {
            $libraries = Library::whereIn('id', $libraryIds->unique())
                ->active()
                ->orderBy('sort_order')
                ->get();
            foreach ($libraries as $lib) {
                if ($lib->load_strategy === 'cdn') {
                    if ($lib->css_cdn) {
                        $cdnHead .= '<link rel="stylesheet" href="' . e($lib->css_cdn) . '">' . "\n";
                    }
                    if ($lib->js_cdn) {
                        $cdnScripts .= '<script src="' . e($lib->js_cdn) . '"></script>' . "\n";
                    }
                } elseif ($lib->load_strategy === 'local') {
                    if ($lib->css_file) {
                        $cdnHead .= '<link rel="stylesheet" href="' . e(asset('storage/' . ltrim($lib->css_file, '/'))) . '">' . "\n";
                    }
                    if ($lib->js_file) {
                        $cdnScripts .= '<script src="' . e(asset('storage/' . ltrim($lib->js_file, '/'))) . '"></script>' . "\n";
                    }
                }
            }
        }

        $allCss = implode("\n", array_filter([$templateCss, $css]));
        $allJs = implode("\n", array_filter([$templateJs, $js]));

        $html = $this->blockRenderer->renderPreviewWrapper([
            'cdnCss' => $cdnHead,
            'css' => $allCss,
            'content' => $blockHtml,
            'cdnJs' => $cdnScripts,
            'js' => $allJs,
            'postScript' => "function sendHeight(){window.parent.postMessage({type:'blockHeight',height:document.body.scrollHeight,id:{$pb->id}},'*')}\nnew ResizeObserver(sendHeight).observe(document.body);\nsendHeight();",
        ]);

        return $this->success(['html' => $html]);
    }

    /** @OA\Get(path="/page-blocks/{id}/versions", summary="Версии данных блока", tags={"Page Blocks"}, security={{"bearerAuth":{}}}, @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")), @OA\Response(response=200, description="Список версий")) */
    public function versions(int $id): JsonResponse
    {
        $pb = PageBlock::findOrFail($id);
        $versions = $pb->versions()->with('user')->get();

        return $this->success(PageBlockDataResource::collection($versions));
    }

    /** @OA\Get(path="/page-blocks/{id}/versions/{versionId}", summary="Одна версия данных блока", tags={"Page Blocks"}, security={{"bearerAuth":{}}}, @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")), @OA\Parameter(name="versionId", in="path", required=true, @OA\Schema(type="integer")), @OA\Response(response=200, description="Версия")) */
    public function showVersion(int $id, int $versionId): JsonResponse
    {
        $pb = PageBlock::findOrFail($id);
        $version = $pb->versions()->with('user')->findOrFail($versionId);

        return $this->success(new PageBlockDataResource($version));
    }

    /** @OA\Put(path="/page-blocks/{id}/version/{versionId}", summary="Установить активную версию", tags={"Page Blocks"}, security={{"bearerAuth":{}}}, @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")), @OA\Parameter(name="versionId", in="path", required=true, @OA\Schema(type="integer")), @OA\Response(response=200, description="Версия установлена")) */
    public function setActiveVersion(int $id, int $versionId): JsonResponse
    {
        $pb = PageBlock::findOrFail($id);
        $version = $pb->versions()->findOrFail($versionId);

        $pb->update([
            'page_block_data_id' => $version->id,
            'data' => $version->data,
            'action_params' => $version->action_params,
        ]);

        $this->cacheManager->invalidateBlock($pb);

        $this->logAction('set_version', 'page_block', $pb->id, ['version_id' => $versionId]);

        return $this->success(new PageBlockResource($pb->fresh(['block', 'preset'])), 'Активная версия установлена.');
    }
}
