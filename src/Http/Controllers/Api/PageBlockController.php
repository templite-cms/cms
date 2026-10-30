<?php

namespace Templite\Cms\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Templite\Cms\Http\Resources\PageBlockResource;
use Templite\Cms\Models\BlockPreset;
use Templite\Cms\Models\CmsConfig;
use Templite\Cms\Models\Page;
use Templite\Cms\Models\PageBlock;
use Templite\Cms\Models\TemplatePage;
use Templite\Cms\Services\ActionRunner;
use Templite\Cms\Services\BlockDataResolver;
use Templite\Cms\Services\BlockRenderer;
use Templite\Cms\Services\LibraryAssetBuilder;
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
        ])->findOrFail($id);

        return $this->success($pb);
    }

    /** @OA\Post(path="/pages/{id}/blocks", summary="Добавить блок на страницу", tags={"Page Blocks"}, security={{"bearerAuth":{}}}, @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")), @OA\RequestBody(required=true, @OA\JsonContent(@OA\Property(property="block_id", type="integer", description="Обязателен без copy_from"), @OA\Property(property="copy_from", type="integer", description="ID экземпляра блока: вставка копии (буфер)"), @OA\Property(property="data", type="object"), @OA\Property(property="order", type="integer"), @OA\Property(property="preset_id", type="integer"))), @OA\Response(response=201, description="Блок добавлен")) */
    public function store(Request $request, int $id): JsonResponse
    {
        $page = Page::findOrFail($id);
        $data = $request->validate([
            'block_id' => 'required_without:copy_from|integer|exists:blocks,id',
            'copy_from' => 'nullable|integer|exists:page_blocks,id',
            'data' => 'nullable|array',
            'order' => 'integer',
            'preset_id' => 'nullable|integer|exists:block_presets,id',
        ]);

        // Вставка из буфера: копия актуального состояния исходного экземпляра
        if ($copyFrom = $data['copy_from'] ?? null) {
            $source = PageBlock::findOrFail($copyFrom);
            $data = array_merge($data, [
                'block_id' => $source->block_id,
                'data' => $source->data ?? [],
                'action_params' => $source->action_params,
                'cache_enabled' => $source->cache_enabled,
                'cache_key' => $source->cache_key,
                'preset_id' => $source->preset_id,
            ]);
        }
        unset($data['copy_from']);

        $presetId = $copyFrom ? null : ($data['preset_id'] ?? null);
        if (! $copyFrom) {
            unset($data['preset_id']);
        }

        // Блок без пресета и без данных получает стартовый контент из пресета по умолчанию.
        if (! $copyFrom && ! $presetId && empty($data['data'])) {
            $presetId = BlockPreset::defaultForBlock($data['block_id'])?->id;
        }

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
                    // Локальный пресет — только стартовые данные; связи с ним нет
                    $pb->update([
                        'data' => $preset->data ?? [],
                    ]);
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
            'copy_from' => $copyFrom,
        ]);

        // Перечитываем модель: колонки с дефолтами БД (cache_enabled и т.п.) после
        // create() в атрибутах отсутствуют и уходят в ответ как null — редактор
        // затем шлёт этот null обратно и падает на NOT NULL.
        $pb->refresh();

        return $this->success(new PageBlockResource($pb->load([
            'block.blockType',
            'block.blockActions.action',
            'block.fields.children',
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
            // Связь — только с глобальным пресетом этого же блока; null — отвязать
            'preset_id' => [
                'nullable', 'integer',
                Rule::exists('block_presets', 'id')->where('type', 'global')->where('block_id', $pb->block_id),
            ],
        ]);

        if ($data !== []) {
            $pb->update($data);
        }

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

    /** @OA\Put(path="/page-blocks/{id}/cache", summary="Переключить кэш блока", tags={"Page Blocks"}, security={{"bearerAuth":{}}}, @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")), @OA\Response(response=200, description="Обновлено")) */
    public function toggleCache(Request $request, int $id): JsonResponse
    {
        $pb = PageBlock::findOrFail($id);
        $data = $request->validate(['cache_enabled' => 'required|boolean', 'cache_key' => 'nullable|string']);
        $pb->update($data);
        if (!$data['cache_enabled']) {
            $this->cacheManager->invalidateBlock($pb);
        }

        $this->logAction('update', 'page_block', $pb->id, ['cache_enabled' => $data['cache_enabled'], 'page_id' => $pb->page_id]);

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

    /**
     * Превью экземпляра блока в редакторе страницы.
     *
     * Несохранённые данные приходят в теле запроса и в БД не пишутся:
     * черновиков нет, сохраняет только «Сохранить страницу».
     *
     * @OA\Post(path="/page-blocks/{id}/render", summary="Превью блока на странице", tags={"Page Blocks"}, security={{"sanctumAuth":{}}}, @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")), @OA\RequestBody(@OA\JsonContent(@OA\Property(property="data", type="object"), @OA\Property(property="action_params", type="object"), @OA\Property(property="template_id", type="integer"))), @OA\Response(response=200, description="{html}"))
     */
    public function render(Request $request, int $id): JsonResponse
    {
        $input = $request->validate([
            'data' => 'nullable|array',
            'action_params' => 'nullable|array',
            'template_id' => 'nullable|integer',
        ]);

        $pb = PageBlock::with(['block.fields', 'block.blockActions.action', 'block.libraries.files', 'preset'])
            ->findOrFail($id);

        $page = Page::with('templatePage.libraries.files')->findOrFail($pb->page_id);

        // Override template for preview when switching template without saving
        $overrideTemplateId = $input['template_id'] ?? null;
        if ($overrideTemplateId !== null) {
            $overrideTemplateId = (int) $overrideTemplateId;
            if ($overrideTemplateId && $overrideTemplateId !== $page->template_page_id) {
                $page->setRelation(
                    'templatePage',
                    TemplatePage::with('libraries.files')->find($overrideTemplateId)
                );
            } elseif (!$overrideTemplateId) {
                $page->setRelation('templatePage', null);
            }
        }

        // Load global fields for components that rely on $global (e.g. header)
        $global = app()->bound('global_fields') ? app('global_fields') : [];
        view()->share('global', $global);

        // Данные из редактора — только в памяти, модель не сохраняется
        foreach (['data', 'action_params'] as $key) {
            if (array_key_exists($key, $input)) {
                $pb->{$key} = $input[$key];
            }
        }

        $this->blockDataResolver->resolvePageBlocks(collect([$pb]));
        $resolvedData = $pb->resolved_data;
        $actionParams = $pb->action_params ?? [];

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

        // У блока может не быть кода (например, тип создан, а шаблон ещё не написан):
        // renderer отдаёт HTML-комментарий, и в редакторе блок выглядит пустым без
        // объяснения. На сайте комментарий уместен, в админке — говорим прямо.
        if (str_starts_with(trim($blockHtml), '<!-- Block ')) {
            $editUrl = '/'.ltrim(CmsConfig::getAdminUrl(), '/')."/blocks/{$pb->block_id}/edit";
            $blockHtml = '<div style="padding:24px;text-align:center;font-family:sans-serif;font-size:14px;color:#94a3b8;border:1px dashed #cbd5e1;border-radius:8px;margin:8px">'
                .'У блока «'.e($pb->block->name).'» нет кода шаблона.<br>'
                .'<a href="'.$editUrl.'" target="_blank" style="color:#4480f4">Открыть редактор блока</a> и написать вёрстку.'
                .'</div>';
        }

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

        // Библиотеки блока и шаблона — одной коллекцией: билдер сам фильтрует
        // по active, сортирует и дедуплицирует.
        $previewLibraries = collect();
        if ($pb->block->libraries) {
            $previewLibraries = $previewLibraries->merge($pb->block->libraries);
        }
        if ($page->templatePage && $page->templatePage->libraries) {
            $previewLibraries = $previewLibraries->merge($page->templatePage->libraries);
        }

        $libraryHtml = app(LibraryAssetBuilder::class)->htmlFor($previewLibraries);
        $cdnHead = $libraryHtml['head'];
        $cdnScripts = $libraryHtml['body'];

        $allCss = implode("\n", array_filter([$templateCss, $css]));
        // Тот же рантайм, что на сайте: скрипт блока получает el и ждёт готовности DOM
        $allJs = $this->blockRenderer->previewScript([[$pb->block, $js]], $templateJs);

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
}
