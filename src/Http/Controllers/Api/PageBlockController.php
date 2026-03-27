<?php

namespace Templite\Cms\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Templite\Cms\Http\Resources\PageBlockDataResource;
use Templite\Cms\Http\Resources\PageBlockResource;
use Illuminate\Http\Response;
use Templite\Cms\Models\BlockPreset;
use Templite\Cms\Models\Library;
use Templite\Cms\Models\Page;
use Templite\Cms\Models\PageBlock;
use Templite\Cms\Models\PageBlockData;
use Templite\Cms\Models\TemplatePage;
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

    /** @OA\Post(path="/page-blocks/{id}/copy", summary="Копировать блок в буфер", tags={"Page Blocks"}, security={{"bearerAuth":{}}}, @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")), @OA\Response(response=201, description="Блок скопирован в буфер")) */
    public function copy(int $id): JsonResponse
    {
        $pb = PageBlock::findOrFail($id);

        $bufferItem = PageBlockData::create([
            'page_block_id' => null,
            'block_id' => $pb->block_id,
            'data' => $pb->data ?? [],
            'action_params' => $pb->action_params ?? [],
            'user_id' => auth()->id(),
            'change_type' => 'copy',
        ]);

        $this->logAction('copy_to_buffer', 'page_block', $pb->id, ['buffer_id' => $bufferItem->id]);

        return $this->success(
            new PageBlockDataResource($bufferItem->load(['block.blockType', 'block.screenshot', 'user'])),
            'Блок скопирован в буфер.',
            201
        );
    }

    /** @OA\Get(path="/page-block-data/buffer", summary="Буфер скопированных блоков", tags={"Page Blocks"}, security={{"bearerAuth":{}}}, @OA\Response(response=200, description="Список блоков в буфере")) */
    public function buffer(): JsonResponse
    {
        $items = PageBlockData::where('user_id', auth()->id())
            ->where('change_type', 'copy')
            ->whereNull('page_block_id')
            ->with(['block.blockType', 'block.screenshot', 'user'])
            ->orderByDesc('created_at')
            ->get();

        return $this->success(PageBlockDataResource::collection($items));
    }

    /** @OA\Post(path="/page-block-data/{id}/paste", summary="Вставить блок из буфера на страницу", tags={"Page Blocks"}, security={{"bearerAuth":{}}}, @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")), @OA\RequestBody(required=true, @OA\JsonContent(required={"page_id"}, @OA\Property(property="page_id", type="integer"), @OA\Property(property="position", type="integer"))), @OA\Response(response=201, description="Блок вставлен")) */
    public function paste(Request $request, int $id): JsonResponse
    {
        $bufferItem = PageBlockData::where('id', $id)
            ->where('user_id', auth()->id())
            ->where('change_type', 'copy')
            ->whereNull('page_block_id')
            ->firstOrFail();

        $data = $request->validate([
            'page_id' => 'required|integer|exists:pages,id',
            'position' => 'nullable|integer|min:0',
        ]);

        $pageId = $data['page_id'];
        $position = $data['position'] ?? null;

        if ($position !== null) {
            PageBlock::where('page_id', $pageId)
                ->where('order', '>=', $position)
                ->increment('order');
            $order = $position;
        } else {
            $order = PageBlock::where('page_id', $pageId)->max('order') + 1;
        }

        $pb = PageBlock::create([
            'page_id' => $pageId,
            'block_id' => $bufferItem->block_id,
            'data' => $bufferItem->data ?? [],
            'action_params' => $bufferItem->action_params ?? [],
            'order' => $order,
            'cache_enabled' => false,
        ]);

        $this->logAction('paste_from_buffer', 'page_block', $pb->id, [
            'page_id' => $pageId,
            'buffer_id' => $bufferItem->id,
        ]);

        return $this->success(new PageBlockResource($pb->load([
            'block.blockType',
            'block.blockActions.action',
            'block.fields.children',
            'block.tabs',
            'block.sections',
            'preset',
        ])), 'Блок вставлен из буфера.', 201);
    }

    /** @OA\Delete(path="/page-block-data/{id}", summary="Удалить элемент буфера", tags={"Page Blocks"}, security={{"bearerAuth":{}}}, @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")), @OA\Response(response=200, description="Удалено")) */
    public function destroyBufferItem(int $id): JsonResponse
    {
        $item = PageBlockData::where('id', $id)
            ->where('user_id', auth()->id())
            ->where('change_type', 'copy')
            ->whereNull('page_block_id')
            ->firstOrFail();

        $item->delete();

        $this->logAction('delete_buffer_item', 'page_block_data', $id);

        return $this->success(null, 'Элемент буфера удалён.');
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

    /** @OA\Post(path="/page-blocks/{id}/invalidate-cache", summary="Сбросить кэш блока", tags={"Page Blocks"}, security={{"bearerAuth":{}}}, @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")), @OA\Response(response=200, description="Кэш сброшен")) */
    public function invalidateCache(int $id): JsonResponse
    {
        $pb = PageBlock::findOrFail($id);
        $this->cacheManager->invalidateBlock($pb);

        $this->logAction('invalidate_cache', 'page_block', $pb->id, ['page_id' => $pb->page_id]);

        return $this->success(null, 'Кэш блока сброшен.');
    }

    /** @OA\Get(path="/page-blocks/{id}/render", summary="Рендер блока (HTML-страница)", tags={"Page Blocks"}, security={{"sanctumAuth":{}}}, @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")), @OA\Parameter(name="draft", in="query", required=false, @OA\Schema(type="integer"), description="ID черновика PageBlockData"), @OA\Response(response=200, description="HTML-страница с отрендеренным блоком", @OA\MediaType(mediaType="text/html"))) */
    public function render(Request $request, int $id): Response
    {
        $pb = PageBlock::with(['block.fields.children', 'block.blockActions.action', 'block.libraries', 'preset'])
            ->findOrFail($id);

        $page = Page::with('templatePage.libraries')->findOrFail($pb->page_id);

        // Override template for preview when switching template without saving
        $overrideTemplateId = $request->query('template_id');
        if ($overrideTemplateId !== null) {
            $overrideTemplateId = (int) $overrideTemplateId;
            if ($overrideTemplateId && $overrideTemplateId !== $page->template_page_id) {
                $page->setRelation(
                    'templatePage',
                    TemplatePage::with('libraries')->find($overrideTemplateId)
                );
            } elseif (!$overrideTemplateId) {
                $page->setRelation('templatePage', null);
            }
        }

        // Load global fields for components that rely on $global (e.g. header)
        $global = app()->bound('global_fields') ? app('global_fields') : [];
        view()->share('global', $global);

        // If draft query param — load data from PageBlockData; otherwise use PageBlock.data
        $draftId = $request->query('draft');
        if ($draftId) {
            $draft = PageBlockData::findOrFail((int) $draftId);
            abort_if($draft->page_block_id !== $pb->id, 403, 'Draft does not belong to this page block.');

            $data = $draft->data ?? [];

            // Merge with global preset data when applicable
            if ($pb->preset_id && $pb->preset && $pb->preset->type === 'global') {
                $presetData = $pb->preset->data ?? [];
                $overrides = $pb->field_overrides ?? [];
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
            $actionParams = $draft->action_params ?? $pb->action_params ?? [];
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

        return response($html, 200, ['Content-Type' => 'text/html']);
    }

    /** @OA\Put(path="/page-blocks/{id}/draft", summary="Создать/обновить черновик блока", tags={"Page Blocks"}, security={{"sanctumAuth":{}}}, @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")), @OA\RequestBody(required=true, @OA\JsonContent(@OA\Property(property="data", type="object"), @OA\Property(property="action_params", type="object"))), @OA\Response(response=200, description="draft_id черновика")) */
    public function draft(Request $request, int $id): JsonResponse
    {
        $pb = PageBlock::findOrFail($id);
        $data = $request->validate([
            'data' => 'nullable|array',
            'action_params' => 'nullable|array',
        ]);

        // Upsert: find existing updating draft for this page_block + current user
        $draft = PageBlockData::updateOrCreate(
            [
                'page_block_id' => $pb->id,
                'user_id' => auth()->id(),
                'change_type' => 'updating',
            ],
            [
                'block_id' => $pb->block_id,
                'data' => $data['data'] ?? $pb->data,
                'action_params' => $data['action_params'] ?? $pb->action_params,
            ]
        );

        return $this->success(['draft_id' => $draft->id]);
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

    /** @OA\Delete(path="/page-blocks/{id}/versions/{versionId}", summary="Удалить версию данных блока", tags={"Page Blocks"}, security={{"bearerAuth":{}}}, @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")), @OA\Parameter(name="versionId", in="path", required=true, @OA\Schema(type="integer")), @OA\Response(response=200, description="Версия удалена")) */
    public function destroyVersion(int $id, int $versionId): JsonResponse
    {
        $pb = PageBlock::findOrFail($id);
        $version = $pb->versions()->findOrFail($versionId);

        // Cannot delete active version
        abort_if($pb->page_block_data_id === $version->id, 422, 'Нельзя удалить активную версию.');

        $version->delete();

        $this->logAction('delete_version', 'page_block', $pb->id, ['version_id' => $versionId]);

        return $this->success(null, 'Версия удалена.');
    }

    /** @OA\Delete(path="/page-blocks/{id}/versions", summary="Удалить все неактивные версии", tags={"Page Blocks"}, security={{"bearerAuth":{}}}, @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")), @OA\Response(response=200, description="Неактивные версии удалены")) */
    public function destroyInactiveVersions(int $id): JsonResponse
    {
        $pb = PageBlock::findOrFail($id);

        $deleted = $pb->versions()
            ->where('id', '!=', $pb->page_block_data_id ?? 0)
            ->delete();

        $this->logAction('delete_inactive_versions', 'page_block', $pb->id, ['deleted_count' => $deleted]);

        return $this->success(['deleted' => $deleted], 'Неактивные версии удалены.');
    }
}
