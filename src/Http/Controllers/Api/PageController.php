<?php

namespace Templite\Cms\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Templite\Cms\Http\Resources\PageCollection;
use Templite\Cms\Http\Resources\PageResource;
use Templite\Cms\Models\Page;
use Templite\Cms\Models\PageBlock;
use Templite\Cms\Services\BlockDataResolver;
use Templite\Cms\Services\BlockRenderer;
use Templite\Cms\Services\FileService;
use Templite\Cms\Services\ImageProcessor;
use Templite\Cms\Helpers\StringHelper;
use Templite\Cms\Services\CacheManager;
use Templite\Cms\Services\HandlerRegistry;
use Templite\Cms\Services\LibraryAssetBuilder;
use Templite\Cms\Services\UrlGenerator;

class PageController extends Controller
{
    public function __construct(
        protected UrlGenerator $urlGenerator,
        protected BlockRenderer $blockRenderer,
        protected BlockDataResolver $blockDataResolver,
        protected FileService $fileService,
        protected ImageProcessor $imageProcessor,
        protected CacheManager $cacheManager,
        protected HandlerRegistry $handlerRegistry,
    ) {}

    /**
     * @OA\Get(
     *     path="/pages",
     *     summary="Список страниц",
     *     tags={"Pages"},
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(name="page", in="query", @OA\Schema(type="integer")),
     *     @OA\Parameter(name="per_page", in="query", @OA\Schema(type="integer", default=20)),
     *     @OA\Parameter(name="type_id", in="query", @OA\Schema(type="integer")),
     *     @OA\Parameter(name="status", in="query", @OA\Schema(type="integer", enum={0,1})),
     *     @OA\Parameter(name="parent_id", in="query", @OA\Schema(type="integer")),
     *     @OA\Parameter(name="search", in="query", @OA\Schema(type="string")),
     *     @OA\Response(response=200, description="Список страниц"),
     *     @OA\Response(response=401, description="Не авторизован")
     * )
     */
    public function index(Request $request): PageCollection
    {
        $query = Page::with(['pageType', 'image', 'screenshot', 'templatePage']);

        if ($request->has('type_id')) {
            $query->where('type_id', $request->type_id);
        }
        if ($request->has('status')) {
            $query->where('status', $request->status);
        }
        if ($request->has('parent_id')) {
            $query->where('parent_id', $request->parent_id);
        }
        if ($request->has('search')) {
            $query->where('title', 'like', '%' . StringHelper::escapeLike($request->search) . '%');
        }

        $perPage = min((int) $request->input('per_page', 20), 100);
        $pages = $query->orderBy('order')->paginate($perPage);

        return new PageCollection($pages);
    }

    /**
     * @OA\Get(
     *     path="/pages/tree",
     *     summary="Дерево страниц",
     *     tags={"Pages"},
     *     security={{"bearerAuth":{}}},
     *     @OA\Response(response=200, description="Дерево страниц")
     * )
     */
    public function tree(): JsonResponse
    {
        $pages = Page::with(['image', 'pageType:id,name,icon'])
            ->orderBy('parent_id')
            ->orderBy('order')
            ->get(['id', 'title', 'url', 'alias', 'parent_id', 'type_id', 'status', 'order', 'img']);

        $tree = $this->buildTree($pages);

        return $this->success($tree);
    }

    /**
     * Правила для SEO-ключей, которые читает сайт (views/components/meta-tags.blade.php,
     * Http/Controllers/Web/RenderController.php — редирект страницы).
     * Прочие ключи seo_data/social_data не ограничиваются.
     */
    public static function seoRules(): array
    {
        return [
            'seo_data.title' => 'nullable|string|max:255',
            'seo_data.description' => 'nullable|string|max:1000',
            'seo_data.keywords' => 'nullable|string|max:500',
            // canonical URL (meta-tags.blade.php)
            'seo_data.canonical_url' => 'nullable|string|max:2048',
            // "index, follow" и т.п. (meta-tags.blade.php)
            'seo_data.robots' => 'nullable|string|max:255',
            // URL для редиректа страницы (RenderController::renderPage)
            'seo_data.redirect_url' => 'nullable|string|max:2048',
            'seo_data.redirect_code' => 'nullable|integer|in:301,302',
            'social_data.og_title' => 'nullable|string|max:255',
            'social_data.og_description' => 'nullable|string|max:1000',
            // id файла медиатеки (редактор) или URL картинки (старые данные)
            'social_data.og_image' => ['nullable', function (string $attribute, mixed $value, \Closure $fail) {
                if (!is_int($value) && !(is_string($value) && mb_strlen($value) <= 2048)) {
                    $fail('Картинка для соцсетей: нужен id файла или URL.');
                }
            }],
        ];
    }

    /**
     * Проверить и нормализовать URL, введённый вручную (url_auto = false).
     */
    protected function manualUrl(string $url, ?int $excludeId = null): string
    {
        $url = $this->urlGenerator->normalizeUrl($url);

        if (!preg_match('#^/[a-z0-9\-/]*$#', $url)) {
            throw ValidationException::withMessages([
                'url' => 'URL: только латиница в нижнем регистре, цифры, дефис и «/».',
            ]);
        }
        if (!$this->urlGenerator->isUrlUnique($url, $excludeId)) {
            throw ValidationException::withMessages(['url' => "URL {$url} уже занят другой страницей."]);
        }

        return $url;
    }

    /**
     * Запретить делать страницу дочерней для самой себя или своего потомка.
     */
    protected function assertValidParent(Page $page, ?int $parentId): void
    {
        if ($parentId !== null && in_array($parentId, [$page->id, ...$page->descendantIds()], true)) {
            throw ValidationException::withMessages([
                'parent_id' => 'Нельзя сделать страницу дочерней для самой себя или своей вложенной страницы.',
            ]);
        }
    }

    /**
     * validate() с правилами seo_data.* оставляет в массиве только перечисленные ключи —
     * возвращаем seo_data/social_data целиком, чтобы не терять ключи без правил валидации.
     */
    public static function keepFullSeoData(array $data, Request $request): array
    {
        foreach (['seo_data', 'social_data'] as $key) {
            if (array_key_exists($key, $data)) {
                $data[$key] = $request->input($key);
            }
        }

        return $data;
    }

    /**
     * @OA\Post(
     *     path="/pages",
     *     summary="Создать страницу",
     *     tags={"Pages"},
     *     security={{"bearerAuth":{}}},
     *     @OA\RequestBody(required=true, @OA\JsonContent(
     *         required={"title","alias"},
     *         @OA\Property(property="title", type="string"),
     *         @OA\Property(property="alias", type="string"),
     *         @OA\Property(property="parent_id", type="integer"),
     *         @OA\Property(property="type_id", type="integer"),
     *         @OA\Property(property="template_page_id", type="integer"),
     *         @OA\Property(property="status", type="integer", enum={0,1}),
     *         @OA\Property(property="seo_data", type="object"),
     *         @OA\Property(property="social_data", type="object"),
     *         @OA\Property(property="img", type="integer")
     *     )),
     *     @OA\Response(response=201, description="Страница создана"),
     *     @OA\Response(response=422, description="Ошибка валидации")
     * )
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'title' => 'required|string|max:255',
            'alias' => 'required|string|max:255',
            'url' => 'nullable|string|max:500',
            'url_auto' => 'boolean',
            'parent_id' => 'nullable|integer|exists:pages,id',
            'type_id' => 'nullable|integer|exists:page_types,id',
            'bread_title' => 'nullable|string|max:255',
            'seo_data' => 'nullable|array',
            'social_data' => 'nullable|array',
            ...self::seoRules(),
            'template_page_id' => 'nullable|integer|exists:template_pages,id',
            'template_data' => 'nullable|array',
            'status' => 'integer|in:0,1',
            'handler' => 'nullable|string|max:50',
            'display_tree' => 'boolean',
            'img' => 'nullable|integer|exists:files,id',
            'order' => 'integer',
            'publish_at' => 'nullable|date',
            'unpublish_at' => ['nullable', 'date', $request->filled('publish_at') ? 'after:publish_at' : ''],
        ]);
        $data = self::keepFullSeoData($data, $request);

        $data['url_auto'] = $data['url_auto'] ?? true;
        $data['url'] = !$data['url_auto'] && !empty($data['url'])
            ? $this->manualUrl($data['url'])
            : $this->urlGenerator->generateUniqueUrl($data['alias'], $data['parent_id'] ?? null);

        $page = new Page($data);
        $page->created_by = auth()->id();
        $page->save();

        app(\Templite\Cms\Services\PageAssetCompiler::class)->compile($page);

        $this->logAction('create', 'page', $page->id, ['title' => $page->title, 'slug' => $page->alias]);

        return $this->success(new PageResource($page->load(['pageType', 'image', 'screenshot'])), 'Страница создана.', 201);
    }

    /**
     * @OA\Get(
     *     path="/pages/{id}",
     *     summary="Получить страницу",
     *     tags={"Pages"},
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
     *     @OA\Response(response=200, description="Данные страницы"),
     *     @OA\Response(response=404, description="Не найдена")
     * )
     */
    public function show(int $id): JsonResponse
    {
        $page = Page::with(['pageType.attributes', 'image', 'screenshot', 'templatePage', 'parent'])
            ->findOrFail($id);

        return $this->success(new PageResource($page));
    }

    /**
     * @OA\Put(
     *     path="/pages/{id}",
     *     summary="Обновить страницу",
     *     tags={"Pages"},
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
     *     @OA\RequestBody(required=true, @OA\JsonContent(
     *         @OA\Property(property="title", type="string"),
     *         @OA\Property(property="alias", type="string"),
     *         @OA\Property(property="status", type="integer")
     *     )),
     *     @OA\Response(response=200, description="Обновлено"),
     *     @OA\Response(response=404, description="Не найдена")
     * )
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $page = Page::findOrFail($id);

        $data = $request->validate([
            'title' => 'sometimes|string|max:255',
            'alias' => 'sometimes|nullable|string|max:255',
            'url' => 'sometimes|nullable|string|max:500',
            'url_auto' => 'sometimes|boolean',
            'parent_id' => 'nullable|integer|exists:pages,id',
            'type_id' => 'nullable|integer|exists:page_types,id',
            'bread_title' => 'nullable|string|max:255',
            'seo_data' => 'nullable|array',
            'social_data' => 'nullable|array',
            ...self::seoRules(),
            'template_page_id' => 'nullable|integer|exists:template_pages,id',
            'template_data' => 'nullable|array',
            'status' => 'integer|in:0,1',
            'handler' => 'nullable|string|max:50',
            'display_tree' => 'boolean',
            'img' => 'nullable|integer|exists:files,id',
            'screen' => 'nullable|integer|exists:files,id',
            'order' => 'integer',
            'publish_at' => 'nullable|date',
            'unpublish_at' => ['nullable', 'date', $request->filled('publish_at') ? 'after:publish_at' : ''],
        ]);
        $data = self::keepFullSeoData($data, $request);

        // Нормализуем alias: null/пустой → пустая строка (для главной страницы)
        if (array_key_exists('alias', $data)) {
            $data['alias'] = $data['alias'] ?? '';
        }

        if (array_key_exists('parent_id', $data)) {
            $this->assertValidParent($page, $data['parent_id']);
        }

        // URL: при ручном режиме меняется только явно, при авто — следует за alias и родителем
        $urlAuto = array_key_exists('url_auto', $data) ? (bool) $data['url_auto'] : $page->url_auto;
        $manualUrl = $data['url'] ?? null;
        unset($data['url']);
        $data['url_auto'] = $urlAuto;

        if (!$urlAuto) {
            if (!empty($manualUrl)) {
                $data['url'] = $this->manualUrl($manualUrl, $page->id);
            }
        } elseif (!$page->url_auto || array_key_exists('alias', $data) || array_key_exists('parent_id', $data)) {
            $alias = $data['alias'] ?? $page->alias;
            $parentId = array_key_exists('parent_id', $data) ? $data['parent_id'] : $page->parent_id;
            $data['url'] = $this->urlGenerator->generateUniqueUrl($alias, $parentId, $page->id);
        }

        $oldUrl = $page->url;
        $page->update($data);

        // Потомки с авто-URL строятся от URL родителя
        if ($page->url !== $oldUrl) {
            $this->urlGenerator->updateChildrenUrls($page);
        }

        $this->cacheManager->invalidatePage($page);
        app(\Templite\Cms\Services\PageAssetCompiler::class)->compile($page);

        $this->logAction('update', 'page', $page->id, ['title' => $page->title]);

        return $this->success(new PageResource($page->fresh(['pageType', 'image', 'screenshot'])));
    }

    /**
     * @OA\Delete(
     *     path="/pages/{id}",
     *     summary="Удалить страницу",
     *     tags={"Pages"},
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
     *     @OA\Response(response=200, description="Удалено"),
     *     @OA\Response(response=404, description="Не найдена")
     * )
     */
    public function destroy(int $id): JsonResponse
    {
        $page = Page::findOrFail($id);
        $title = $page->title;
        $this->deletePage($page);

        $this->logAction('delete', 'page', $id, ['title' => $title]);

        return $this->success(null, 'Страница удалена.');
    }

    /**
     * Удалить страницу. Дочерние становятся корневыми (FK nullOnDelete) и сохраняют
     * адрес ради SEO: их URL фиксируется, иначе следующее сохранение пересоберёт его без префикса.
     */
    protected function deletePage(Page $page): void
    {
        Page::where('parent_id', $page->id)->update(['url_auto' => false]);
        $page->delete();
    }

    /**
     * @OA\Post(
     *     path="/pages/bulk",
     *     summary="Массовое действие над страницами",
     *     tags={"Pages"},
     *     security={{"bearerAuth":{}}},
     *     @OA\RequestBody(required=true, @OA\JsonContent(
     *         required={"action","ids"},
     *         @OA\Property(property="action", type="string", enum={"delete","status","parent"}),
     *         @OA\Property(property="ids", type="array", @OA\Items(type="integer")),
     *         @OA\Property(property="status", type="integer", enum={0,1}, description="Для action=status"),
     *         @OA\Property(property="parent_id", type="integer", nullable=true, description="Для action=parent; null — в корень")
     *     )),
     *     @OA\Response(response=200, description="Выполнено"),
     *     @OA\Response(response=422, description="Ошибка валидации")
     * )
     */
    public function bulk(Request $request): JsonResponse
    {
        $data = $request->validate([
            'action' => 'required|string|in:delete,status,parent',
            'ids' => 'required|array|min:1|max:500',
            'ids.*' => 'integer|distinct|exists:pages,id',
            'status' => 'required_if:action,status|integer|in:0,1',
            'parent_id' => 'nullable|integer|exists:pages,id',
        ]);

        $pages = Page::whereIn('id', $data['ids'])->get();

        DB::transaction(function () use ($data, $pages) {
            match ($data['action']) {
                'delete' => $this->bulkDelete($pages),
                'status' => $this->bulkStatus($pages, (int) $data['status']),
                'parent' => $this->bulkParent($pages, $data['parent_id'] ?? null),
            };
        });

        $messages = [
            'delete' => 'Страницы удалены.',
            'status' => 'Статус обновлён.',
            'parent' => 'Родитель изменён.',
        ];

        return $this->success(['count' => $pages->count()], $messages[$data['action']]);
    }

    protected function bulkDelete($pages): void
    {
        foreach ($pages as $page) {
            // Вложенную страницу могли удалить раньше, если её родитель тоже в выборке
            if (!Page::whereKey($page->id)->exists()) {
                continue;
            }
            $this->deletePage($page);
            $this->logAction('delete', 'page', $page->id, ['title' => $page->title]);
        }
    }

    protected function bulkStatus($pages, int $status): void
    {
        foreach ($pages as $page) {
            if ($page->status === $status) {
                continue;
            }
            $page->update(['status' => $status]);
            $this->cacheManager->invalidatePage($page);
            $this->logAction('update_status', 'page', $page->id, ['title' => $page->title, 'status' => $status]);
        }
    }

    protected function bulkParent($pages, ?int $parentId): void
    {
        foreach ($pages as $page) {
            $this->assertValidParent($page, $parentId);
        }

        foreach ($pages as $page) {
            // URL мог измениться при переносе предка из той же выборки
            $page->refresh();
            if ($page->parent_id === $parentId) {
                continue;
            }

            $oldUrl = $page->url;
            $page->parent_id = $parentId;
            // Ручной URL при переносе не меняется
            if ($page->url_auto) {
                $page->url = $this->urlGenerator->generateUniqueUrl($page->alias, $parentId, $page->id);
            }
            $page->save();

            if ($page->url !== $oldUrl) {
                $this->urlGenerator->updateChildrenUrls($page);
            }
            $this->cacheManager->invalidatePage($page);
            $this->logAction('move', 'page', $page->id, ['title' => $page->title, 'parent_id' => $parentId]);
        }
    }

    /**
     * @OA\Post(
     *     path="/pages/{id}/copy",
     *     summary="Копировать страницу",
     *     tags={"Pages"},
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
     *     @OA\Response(response=201, description="Копия создана")
     * )
     */
    public function copy(int $id): JsonResponse
    {
        $page = Page::with('pageBlocks')->findOrFail($id);

        $newPage = $page->replicate(['url', 'views']);
        $newPage->title = $page->title . ' (копия)';
        $newPage->alias = $page->alias . '-copy';
        $newPage->url = $this->urlGenerator->generateUniqueUrl($newPage->alias, $newPage->parent_id);
        $newPage->url_auto = true;
        $newPage->status = 0; // Черновик
        $newPage->publish_at = null;
        $newPage->unpublish_at = null;
        $newPage->created_by = auth()->id();
        $newPage->save();

        // Копируем блоки
        foreach ($page->pageBlocks as $pb) {
            $newPb = $pb->replicate();
            $newPb->page_id = $newPage->id;
            $newPb->save();
        }

        $this->logAction('copy', 'page', $newPage->id, ['title' => $newPage->title, 'source_id' => $id]);

        return $this->success(new PageResource($newPage->load(['pageType', 'image', 'screenshot'])), 'Страница скопирована.', 201);
    }

    /**
     * @OA\Put(
     *     path="/pages/reorder",
     *     summary="Изменить порядок страниц",
     *     tags={"Pages"},
     *     security={{"bearerAuth":{}}},
     *     @OA\RequestBody(required=true, @OA\JsonContent(
     *         @OA\Property(property="items", type="array", @OA\Items(
     *             @OA\Property(property="id", type="integer"),
     *             @OA\Property(property="order", type="integer"),
     *             @OA\Property(property="parent_id", type="integer")
     *         ))
     *     )),
     *     @OA\Response(response=200, description="Порядок обновлён")
     * )
     */
    public function reorder(Request $request): JsonResponse
    {
        $data = $request->validate([
            'items' => 'required|array',
            'items.*.id' => 'required|integer|exists:pages,id',
            'items.*.order' => 'required|integer',
            'items.*.parent_id' => 'nullable|integer',
        ]);

        foreach ($data['items'] as $item) {
            Page::where('id', $item['id'])->update([
                'order' => $item['order'],
                'parent_id' => $item['parent_id'] ?? null,
            ]);
        }

        // Обновляем URL всех затронутых страниц
        foreach ($data['items'] as $item) {
            $page = Page::find($item['id']);
            if ($page) {
                $this->urlGenerator->updateUrlTree($page);
            }
        }

        $this->logAction('reorder', 'page', null, ['count' => count($data['items'])]);

        return $this->success(null, 'Порядок обновлён.');
    }

    /**
     * @OA\Get(
     *     path="/pages/{id}/preview",
     *     summary="Превью страницы (полный HTML)",
     *     tags={"Pages"},
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
     *     @OA\Response(response=200, description="HTML-превью страницы"),
     *     @OA\Response(response=404, description="Не найдена")
     * )
     */
    public function preview(int $id): JsonResponse
    {
        $page = Page::with([
            'templatePage.libraries.files',
            'asset',
        ])->findOrFail($id);

        // Загружаем блоки страницы с eager loading
        $pageBlocks = PageBlock::where('page_id', $page->id)
            ->with([
                'block.fields',
                'block.blockActions.action',
                'block.libraries.files',
                'preset',
            ])
            ->orderBy('order')
            ->get();

        // Глобальные поля
        $global = app()->bound('global_fields') ? app('global_fields') : [];
        view()->share('global', $global);

        // Резолвим данные всех блоков (batch-загрузка file_id -> File, и т.п.)
        $this->blockDataResolver->resolvePageBlocks($pageBlocks);

        // Рендерим каждый блок и собираем CSS/JS
        $blocksHtml = [];
        $cssAll = [];
        $jsAll = [];
        $blockScripts = [];

        foreach ($pageBlocks as $pb) {
            $block = $pb->block;
            if (!$block) {
                continue;
            }

            // Рендерим HTML блока
            try {
                $html = $this->blockRenderer->render(
                    $block,
                    $pb->resolved_data,
                    [],    // actions не выполняются в превью
                    $page,
                    $global
                );
                $blocksHtml[] = $html;
            } catch (\Throwable $e) {
                $blocksHtml[] = '<div style="color:#ef4444;padding:16px;font-family:monospace;font-size:13px">'
                    . '<strong>Block "' . htmlspecialchars($block->slug) . '" Error:</strong><br>'
                    . htmlspecialchars($e->getMessage())
                    . '</div>';
            }

            // CSS блока — один раз на тип, сколько бы экземпляров ни стояло
            if (!isset($cssAll["block:{$block->slug}"])) {
                $cssAll["block:{$block->slug}"] = $this->blockRenderer->compileStyles($block) ?? '';
            }

            // JS блока — пара [блок, код]: обернёт рантайм, повторы экземпляров отбросит
            $blockPath = $this->blockRenderer->resolveBlockPath($block);
            if ($blockPath) {
                $scriptFile = $blockPath . '/script.js';
                if (file_exists($scriptFile)) {
                    $blockScripts[] = [$block, file_get_contents($scriptFile)];
                }
            }
        }

        // Template CSS/JS
        $template = $page->templatePage;
        if ($template) {
            $templateCss = $this->blockRenderer->compileTemplateStyles($template) ?? '';
            $templateJs = $this->blockRenderer->getTemplateScript($template) ?? '';

            if ($templateCss) {
                array_unshift($cssAll, $templateCss);
            }
            if ($templateJs) {
                array_unshift($jsAll, $templateJs);
            }
        }

        // Собираем библиотеки напрямую (шаблон + блоки).
        // Фильтрацию по active, сортировку и дедупликацию делает билдер.
        // Оба loadMissing подстраховочные: связи обязаны быть подняты
        // в with('templatePage.libraries.files') и with('block.libraries.files') выше.
        $previewLibraries = collect();
        if ($template) {
            $template->loadMissing('libraries.files');
            $previewLibraries = $previewLibraries->merge($template->libraries);
        }
        foreach ($pageBlocks as $pb) {
            if ($pb->block) {
                $pb->block->loadMissing('libraries.files');
                $previewLibraries = $previewLibraries->merge($pb->block->libraries);
            }
        }

        $libraryHtml = app(LibraryAssetBuilder::class)->htmlFor($previewLibraries);
        $cdnCssLinks = $libraryHtml['head'];
        $cdnJsLinks = $libraryHtml['body'];

        // Component styles/scripts from block and template Blade content
        $bladeContents = [];
        foreach ($pageBlocks as $pb) {
            if ($pb->block) {
                $blockPath = $this->blockRenderer->resolveBlockPath($pb->block);
                if ($blockPath && file_exists($blockPath . '/template.blade.php')) {
                    $bladeContents[] = file_get_contents($blockPath . '/template.blade.php');
                }
            }
        }
        if ($template) {
            $tplFile = storage_path('cms/templates/' . basename($template->slug) . '/template.blade.php');
            if (file_exists($tplFile)) {
                $bladeContents[] = file_get_contents($tplFile);
            }
        }
        if ($bladeContents) {
            $componentAssets = $this->blockRenderer->collectComponentAssets(...$bladeContents);
            if ($componentAssets['css']) {
                $cssAll[] = $componentAssets['css'];
            }
            if ($componentAssets['js']) {
                $jsAll[] = $componentAssets['js'];
            }
        }

        $combinedCss = implode("\n", $cssAll);
        // Тот же рантайм, что на сайте: скрипты шаблона и компонентов, затем блоки
        $combinedJs = $this->blockRenderer->previewScript($blockScripts, implode("\n", $jsAll));
        $combinedBlocks = implode("\n", $blocksHtml);

        $fullHtml = $this->blockRenderer->renderPreviewWrapper([
            'cdnCss' => $cdnCssLinks,
            'css' => $combinedCss,
            'content' => $combinedBlocks,
            'cdnJs' => $cdnJsLinks,
            'js' => $combinedJs,
        ]);

        return $this->success(['html' => $fullHtml]);
    }

    /**
     * @OA\Post(
     *     path="/pages/{id}/screenshot",
     *     summary="Загрузить скриншот страницы",
     *     tags={"Pages"},
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
     *     @OA\RequestBody(required=true, @OA\MediaType(
     *         mediaType="multipart/form-data",
     *         @OA\Schema(@OA\Property(property="screenshot", type="string", format="binary"))
     *     )),
     *     @OA\Response(response=200, description="Скриншот сохранён"),
     *     @OA\Response(response=404, description="Не найдена"),
     *     @OA\Response(response=422, description="Ошибка валидации")
     * )
     */
    public function screenshot(Request $request, int $id): JsonResponse
    {
        $page = Page::with('screenshot')->findOrFail($id);

        $request->validate([
            'screenshot' => 'required|image|max:5120|mimes:jpeg,png,webp',
        ]);

        $file = DB::transaction(function () use ($request, $page) {
            // Удаляем старый скриншот
            if ($page->screen && $page->screenshot) {
                $this->fileService->delete($page->screenshot);
            }

            // Загружаем новый файл
            $file = $this->fileService->upload($request->file('screenshot'));

            // Привязываем к странице
            $page->update(['screen' => $file->id]);

            return $file;
        });

        // Создаём ресайзы (вне транзакции — graceful degradation)
        try {
            $this->imageProcessor->processImage($file, [
                'sizes' => config('cms.page_screenshot_sizes', [
                    'thumb' => ['width' => 400, 'height' => 225, 'fit' => 'cover'],
                    'medium' => ['width' => 960, 'height' => null, 'fit' => 'contain'],
                ]),
                'formats' => ['original', 'webp'],
                'quality' => config('cms.default_image_quality', 85),
            ]);
        } catch (\Throwable $e) {
            Log::warning("Page screenshot resize failed for file #{$file->id}: {$e->getMessage()}");
        }

        return $this->success(
            new PageResource($page->fresh(['pageType', 'image', 'screenshot'])),
            'Скриншот сохранён.'
        );
    }

    /**
     * Получить список доступных handler'ов.
     */
    public function handlers(): JsonResponse
    {
        return $this->success($this->handlerRegistry->all());
    }

    /**
     * Построить дерево из плоского списка.
     */
    protected function buildTree($pages, ?int $parentId = null): array
    {
        $tree = [];

        foreach ($pages as $page) {
            if ($page->parent_id === $parentId) {
                $node = $page->toArray();
                if ($page->pageType) {
                    $node['type'] = [
                        'name' => $page->pageType->name,
                        'icon' => $page->pageType->icon,
                    ];
                }
                $node['children'] = $this->buildTree($pages, $page->id);
                $tree[] = $node;
            }
        }

        return $tree;
    }
}
