<?php

namespace Templite\Cms\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\View;
use Templite\Cms\Models\Page;
use Templite\Cms\Models\PageBlock;

class PageRenderer
{
    public function __construct(
        protected BlockDataResolver $blockDataResolver,
        protected BlockRenderer $blockRenderer,
        protected ActionRunner $actionRunner,
        protected BreadcrumbGenerator $breadcrumbGenerator,
        protected CacheManager $cacheManager,
        protected LibraryAssetBuilder $libraryAssets,
    ) {}

    /**
     * Рендер полной страницы: блоки + шаблон + глобальные + меню + хлебные крошки.
     */
    public function render(Page $page, Request $request): string
    {
        // Загружаем глобальные поля (middleware должен был установить)
        $global = app()->bound('global_fields') ? app('global_fields') : [];

        // Генерируем хлебные крошки
        $breadcrumbs = $this->breadcrumbGenerator->generate($page);

        // Загружаем блоки страницы с eager loading (только опубликованные для публики, + черновики для менеджера)
        $isManager = auth('manager')->check();
        $pageBlocks = PageBlock::where('page_id', $page->id)
            ->visible($isManager)
            ->with(['block.fields', 'block.blockActions.action', 'preset'])
            ->orderBy('order')
            ->get();

        // Проверяем кэш блоков и запоминаем результат, чтобы не дёргать кэш повторно
        $cachedHtml = [];
        $uncachedBlocks = $pageBlocks->filter(function (PageBlock $pb) use (&$cachedHtml) {
            if (!$pb->cache_enabled) {
                return true;
            }
            $cached = $this->cacheManager->getBlockCache($pb);
            if ($cached !== null) {
                $cachedHtml[$pb->id] = $cached;
                return false;
            }
            return true;
        });

        // Резолвим данные только для блоков без активного кэша
        $this->blockDataResolver->resolvePageBlocks($uncachedBlocks);

        // Рендерим блоки страницы
        $renderedPageBlocks = $this->renderBlockCollection(
            $pageBlocks,
            $page,
            $request,
            $global,
            $cachedHtml
        );

        $blocksContent = implode("\n", $renderedPageBlocks);

        // Load compiled assets
        $assets = $this->buildAssets($page);

        $viewData = [
            'page' => $page,
            'global' => $global,
            'breadcrumbs' => $breadcrumbs,
            'assets' => $assets,
            // Язык сайта — для <html lang>. Шаблоны пишут {{ $lang ?? 'ru' }}.
            'lang' => config('app.locale'),
            // Поля шаблона. В превью редактора они приходят как $fields, и
            // вёрстка пишется под них — а на живой странице переменной не было
            // вовсе: аксессор resolved_template_data существовал, но его никто
            // не вызывал. Шаблон с {{ $fields['ключ'] }} молча выводил пустоту.
            'fields' => $page->resolved_template_data,
        ];

        // Рендерим через шаблон (TemplatePage) если он привязан
        $template = $page->templatePage;

        if ($template) {
            $templatePath = storage_path('cms/templates/' . basename($template->slug));

            if (is_dir($templatePath) && file_exists($templatePath . '/template.blade.php')) {
                $namespace = 'cms_template_' . basename($template->slug);
                View::addNamespace($namespace, $templatePath);

                // page-wrapper расширяет шаблон и заполняет секцию 'blocks'
                $html = view('cms::render.page-wrapper', array_merge($viewData, [
                    '__template_view' => "{$namespace}::template",
                    '__blocks_content' => $blocksContent,
                ]))->render();

                return $this->injectEditButton($html, $page, $request);
            }
        }

        // Fallback: рендерим через базовый layout без шаблона
        $html = view('cms::layouts.page', array_merge($viewData, [
            '__blocks_content' => $blocksContent,
        ]))->render();

        return $this->injectEditButton($html, $page, $request);
    }

    /**
     * Ассеты страницы для шаблона: бандлы блоков и подключения библиотек.
     *
     * @return array{css: ?string, js: ?string, cdn_css: array<int, string>, cdn_js: array<int, string>, libraries_css_html: string, libraries_js_html: string}
     */
    protected function buildAssets(Page $page): array
    {
        $page->loadMissing('asset');

        $cssLinks = $page->asset?->cdnCssLinks() ?? [];
        $jsLinks = $page->asset?->cdnJsLinks() ?? [];

        return [
            'css' => $page->asset?->cssUrl(),
            'js' => $page->asset?->jsUrl(),
            // Устаревшие ключи: массивы голых URL без флагов подключения.
            // Оставлены ради шаблонов, созданных до появления пофайловых
            // флагов, — такие шаблоны сами строят теги через @foreach.
            // css дедуплицируется: билдер считает один и тот же файл с разным
            // media двумя подключениями, но без media это был бы дубль тега.
            'cdn_css' => array_values(array_unique(array_column($cssLinks, 'url'))),
            'cdn_js' => array_column($jsLinks, 'url'),
            // Актуальные ключи: готовая разметка с media/defer/async/module
            'libraries_css_html' => $this->libraryAssets->cssTags($cssLinks),
            'libraries_js_html' => $this->libraryAssets->jsTags($jsLinks),
        ];
    }

    /**
     * Рендерить коллекцию блоков.
     */
    protected function renderBlockCollection(
        $pageBlocks,
        Page $page,
        Request $request,
        array $global,
        array $cachedHtml = []
    ): array {
        $rendered = [];

        foreach ($pageBlocks as $pb) {
            $rendered[] = $this->renderSingleBlock($pb, $page, $request, $global, $cachedHtml);
        }

        return $rendered;
    }

    /**
     * Рендерить один блок (с кэшированием).
     */
    protected function renderSingleBlock(
        PageBlock $pb,
        Page $page,
        Request $request,
        array $global,
        array $cachedHtml = []
    ): string {
        // Используем предварительно загруженный кэш
        if (isset($cachedHtml[$pb->id])) {
            return $cachedHtml[$pb->id];
        }

        $block = $pb->block;

        if (!$block) {
            return '';
        }

        // Выполняем actions блока с page-level overrides
        $actions = $this->actionRunner->run(
            $block,
            $pb->resolved_data,
            $page,
            $request,
            $global,
            $pb->action_params ?? []
        );

        // Рендерим блок через единый BlockRenderer
        $html = $this->blockRenderer->render(
            $block,
            $pb->resolved_data,
            $actions,
            $page,
            $global
        );

        // Сохраняем в кэш
        if ($pb->cache_enabled) {
            $this->cacheManager->putBlockCache($pb, $html);
        }

        return $html;
    }

    /**
     * Инжектировать кнопку «Редактировать» перед </body> если менеджер авторизован.
     */
    protected function injectEditButton(string $html, Page $page, Request $request): string
    {
        $editBtn = $this->renderEditButton($page, $request);

        if ($editBtn) {
            $html = str_replace('</body>', $editBtn . "\n</body>", $html);
        }

        return $html;
    }

    /**
     * Рендер inline-кнопки «Редактировать» для авторизованного менеджера.
     */
    protected function renderEditButton(Page $page, Request $request): string
    {
        // Проверяем сессионную авторизацию менеджера
        if (!auth('manager')->check()) {
            return '';
        }

        $editUrl = route('cms.pages.edit', $page->id);

        return <<<HTML
        <a href="{$editUrl}" id="cms-edit-btn" style="position:fixed;bottom:16px;left:50%;transform:translateX(-50%);z-index:99999;display:inline-flex;align-items:center;gap:6px;padding:6px 16px;background:rgba(37,99,235,.9);color:#fff;font:500 13px/1 system-ui,sans-serif;border-radius:8px;text-decoration:none;box-shadow:0 2px 8px rgba(0,0,0,.2);backdrop-filter:blur(4px);transition:background .15s" onmouseenter="this.style.background='rgba(37,99,235,1)'" onmouseleave="this.style.background='rgba(37,99,235,.9)'">
            <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
            Редактировать
        </a>
        HTML;
    }
}
