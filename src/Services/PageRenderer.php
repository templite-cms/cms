<?php

namespace Templite\Cms\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\View;
use Templite\Cms\Models\City;
use Templite\Cms\Models\CityPage;
use Templite\Cms\Models\CityPageBlock;
use Templite\Cms\Models\Page;
use Templite\Cms\Models\PageBlock;
use Templite\Cms\Models\PageBlockTranslation;
use Templite\Cms\Models\PageTranslation;

class PageRenderer
{
    public function __construct(
        protected BlockDataResolver $blockDataResolver,
        protected BlockRenderer $blockRenderer,
        protected ActionRunner $actionRunner,
        protected BreadcrumbGenerator $breadcrumbGenerator,
        protected CacheManager $cacheManager,
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
            ->with(['block.fields.children', 'block.blockActions.action'])
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

        // Merge translations for current language (только для некэшированных)
        $currentLang = app()->bound('current_language') ? app('current_language') : null;
        $isDefaultLang = app()->bound('is_default_language') ? app('is_default_language') : true;

        if ($currentLang && !$isDefaultLang) {
            $this->mergePageTranslations($page, $currentLang);
            $this->mergeBlockTranslations($uncachedBlocks, $currentLang);
        }

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
        $page->loadMissing('asset');
        $assets = [
            'css' => $page->asset?->cssUrl(),
            'js' => $page->asset?->jsUrl(),
            'cdn_css' => $page->asset?->cdnCssLinks() ?? [],
            'cdn_js' => $page->asset?->cdnJsLinks() ?? [],
        ];

        // Генерируем hreflang-ссылки для мультиязычности
        $hreflangHtml = $this->generateHreflang($page);

        $viewData = [
            'page' => $page,
            'global' => $global,
            'breadcrumbs' => $breadcrumbs,
            'assets' => $assets,
            'lang' => $currentLang,
            'hreflang' => $hreflangHtml,
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
     * Рендер виртуальной городской страницы.
     *
     * Берёт страницу-источник, применяет оверрайды города,
     * подставляет плейсхолдеры и рендерит.
     */
    public function renderVirtualCityPage(
        Page $sourcePage,
        City $city,
        ?CityPage $cityPage,
        Request $request
    ): string {
        $global = app()->bound('global_fields') ? app('global_fields') : [];
        $placeholders = $city->getPlaceholders();

        // Применяем оверрайды к метаданным страницы
        $virtualPage = clone $sourcePage;
        $virtualPage->url = '/' . $city->slug . $sourcePage->url;

        if ($cityPage) {
            $overrides = $cityPage->applyOverrides($sourcePage);
            $virtualPage->title = $overrides['title'];
            $virtualPage->bread_title = $overrides['bread_title'];
            $virtualPage->seo_data = $overrides['seo_data'];
            $virtualPage->social_data = $overrides['social_data'];
            $virtualPage->template_data = $overrides['template_data'];
        }

        // Подставляем плейсхолдеры в title и seo_data
        $virtualPage->title = $this->replacePlaceholders($virtualPage->title, $placeholders);
        $virtualPage->bread_title = $this->replacePlaceholders($virtualPage->bread_title, $placeholders);

        if ($virtualPage->seo_data) {
            $virtualPage->seo_data = $this->replacePlaceholdersInArray($virtualPage->seo_data, $placeholders);
        }
        if ($virtualPage->social_data) {
            $virtualPage->social_data = $this->replacePlaceholdersInArray($virtualPage->social_data, $placeholders);
        }

        // Генерируем хлебные крошки с городом
        $breadcrumbs = $this->breadcrumbGenerator->generateForCity($sourcePage, $city);

        // Загружаем и обрабатываем блоки (только опубликованные для публики, + черновики для менеджера)
        $isManager = auth('manager')->check();
        $pageBlocks = PageBlock::where('page_id', $sourcePage->id)
            ->visible($isManager)
            ->with(['block.fields.children', 'block.blockActions.action'])
            ->orderBy('order')
            ->get();

        // Применяем блочные оверрайды
        $pageBlocks = $this->applyCityBlockOverrides($pageBlocks, $cityPage, $placeholders);

        // Проверяем кэш блоков и запоминаем результат
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

        // Merge translations for current language (только для некэшированных)
        $currentLang = app()->bound('current_language') ? app('current_language') : null;
        $isDefaultLang = app()->bound('is_default_language') ? app('is_default_language') : true;

        if ($currentLang && !$isDefaultLang) {
            $this->mergePageTranslations($virtualPage, $currentLang);
            $this->mergeBlockTranslations($uncachedBlocks, $currentLang);
        }

        // Подставляем плейсхолдеры в данные некэшированных блоков
        foreach ($uncachedBlocks as $pb) {
            if (!empty($pb->resolved_data)) {
                $pb->resolved_data = $this->replacePlaceholdersInArray($pb->resolved_data, $placeholders);
            }
        }

        $renderedPageBlocks = $this->renderBlockCollection($pageBlocks, $virtualPage, $request, $global, $cachedHtml);
        $blocksContent = implode("\n", $renderedPageBlocks);

        // Подставляем плейсхолдеры в финальный HTML блоков
        $blocksContent = $this->replacePlaceholders($blocksContent, $placeholders);

        // Load compiled assets from source page
        $sourcePage->loadMissing('asset');
        $assets = [
            'css' => $sourcePage->asset?->cssUrl(),
            'js' => $sourcePage->asset?->jsUrl(),
            'cdn_css' => $sourcePage->asset?->cdnCssLinks() ?? [],
            'cdn_js' => $sourcePage->asset?->cdnJsLinks() ?? [],
        ];

        // Генерируем hreflang-ссылки для мультиязычности
        $hreflangHtml = $this->generateHreflang($virtualPage);

        $viewData = [
            'page' => $virtualPage,
            'city' => $city,
            'global' => $global,
            'breadcrumbs' => $breadcrumbs,
            'assets' => $assets,
            'lang' => $currentLang,
            'hreflang' => $hreflangHtml,
        ];

        // Рендерим через шаблон (TemplatePage)
        $template = $sourcePage->templatePage;

        if ($template) {
            $templatePath = storage_path('cms/templates/' . basename($template->slug));

            if (is_dir($templatePath) && file_exists($templatePath . '/template.blade.php')) {
                $namespace = 'cms_template_' . basename($template->slug);
                View::addNamespace($namespace, $templatePath);

                $html = view('cms::render.page-wrapper', array_merge($viewData, [
                    '__template_view' => "{$namespace}::template",
                    '__blocks_content' => $blocksContent,
                ]))->render();

                return $this->injectEditButton($html, $sourcePage, $request);
            }
        }

        $html = view('cms::layouts.page', array_merge($viewData, [
            '__blocks_content' => $blocksContent,
        ]))->render();

        return $this->injectEditButton($html, $sourcePage, $request);
    }

    /**
     * Наложить переводы метаданных страницы (title, bread_title, seo_data, social_data).
     */
    protected function mergePageTranslations(Page $page, string $lang): void
    {
        $pageTranslation = $page->translations()->where('lang', $lang)->first();

        if (!$pageTranslation) {
            return;
        }

        if ($pageTranslation->title) {
            $page->title = $pageTranslation->title;
        }
        if ($pageTranslation->bread_title) {
            $page->bread_title = $pageTranslation->bread_title;
        }
        if ($pageTranslation->seo_data) {
            $page->seo_data = $pageTranslation->seo_data;
        }
        if ($pageTranslation->social_data) {
            $page->social_data = $pageTranslation->social_data;
        }
    }

    /**
     * Наложить переводы данных блоков (resolved_data) на коллекцию блоков страницы.
     */
    protected function mergeBlockTranslations($pageBlocks, string $lang): void
    {
        $pbIds = collect($pageBlocks)->pluck('id')->filter()->toArray();

        if (empty($pbIds)) {
            return;
        }

        $blockTranslations = PageBlockTranslation::whereIn('page_block_id', $pbIds)
            ->where('lang', $lang)
            ->get()
            ->keyBy('page_block_id');

        if ($blockTranslations->isEmpty()) {
            return;
        }

        foreach ($pageBlocks as $pb) {
            $bt = $blockTranslations->get($pb->id);
            if ($bt && $bt->data) {
                $pb->resolved_data = array_replace_recursive($pb->resolved_data, $bt->data);
            }
        }
    }

    /**
     * Применить оверрайды блоков для города.
     */
    protected function applyCityBlockOverrides(
        Collection $pageBlocks,
        ?CityPage $cityPage,
        array $placeholders
    ): Collection {
        if (!$cityPage) {
            return $pageBlocks;
        }

        $blockOverrides = $cityPage->blockOverrides()
            ->with('block.fields.children')
            ->get()
            ->keyBy('page_block_id');

        // Фильтруем скрытые блоки и применяем оверрайды
        $result = $pageBlocks->filter(function (PageBlock $pb) use ($blockOverrides) {
            $override = $blockOverrides->get($pb->id);
            return !$override || !$override->isHidden();
        })->map(function (PageBlock $pb) use ($blockOverrides, $placeholders) {
            $override = $blockOverrides->get($pb->id);
            if ($override && $override->isOverride() && $override->data_override) {
                $mergedData = array_merge($pb->data ?? [], $override->data_override);
                $pb->data = $this->replacePlaceholdersInArray($mergedData, $placeholders);
            }
            if ($override && $override->order_override !== null) {
                $pb->order = $override->order_override;
            }
            return $pb;
        });

        // Добавляем блоки, добавленные только для этого города
        $addedBlocks = $cityPage->blockOverrides()
            ->where('action', 'add')
            ->whereNotNull('block_id')
            ->with('block.fields.children')
            ->get();

        foreach ($addedBlocks as $added) {
            if (!$added->block) {
                continue;
            }

            $virtualPb = new PageBlock();
            $virtualPb->id = 0;
            $virtualPb->page_id = $cityPage->source_page_id;
            $virtualPb->block_id = $added->block_id;
            $virtualPb->data = $added->data_override ? $this->replacePlaceholdersInArray($added->data_override, $placeholders) : [];
            $virtualPb->order = $added->order_override ?? 999;
            $virtualPb->status = \Templite\Cms\Enums\PageBlockStatus::Published;
            $virtualPb->cache_enabled = false;
            $virtualPb->setRelation('block', $added->block);
            $result->push($virtualPb);
        }

        return $result->sortBy('order')->values();
    }

    /**
     * Генерация hreflang HTML-ссылок для мультиязычных страниц.
     *
     * Возвращает строку с <link rel="alternate" hreflang="..."> тегами
     * для всех активных языков, включая x-default (язык по умолчанию).
     */
    protected function generateHreflang(Page $page): string
    {
        $currentLang = app()->bound('current_language') ? app('current_language') : null;
        $languages = app()->bound('languages') ? app('languages') : collect();

        if (!$currentLang || $languages->count() <= 1) {
            return '';
        }

        $hreflangHtml = '';

        foreach ($languages as $lang) {
            $langPrefix = $lang->is_default ? '' : '/' . $lang->code;
            $href = e(url($langPrefix . $page->url));
            $code = e($lang->code);
            $hreflangHtml .= '<link rel="alternate" hreflang="' . $code . '" href="' . $href . '" />' . "\n";
        }

        // x-default указывает на URL по умолчанию (без языкового префикса)
        $defaultHref = e(url($page->url));
        $hreflangHtml .= '<link rel="alternate" hreflang="x-default" href="' . $defaultHref . '" />' . "\n";

        return $hreflangHtml;
    }

    /**
     * Подставить плейсхолдеры в строку.
     */
    protected function replacePlaceholders(?string $text, array $placeholders): ?string
    {
        if ($text === null) {
            return null;
        }

        return str_replace(array_keys($placeholders), array_values($placeholders), $text);
    }

    /**
     * Рекурсивно подставить плейсхолдеры в массив.
     */
    protected function replacePlaceholdersInArray(array $data, array $placeholders): array
    {
        foreach ($data as $key => $value) {
            if (is_string($value)) {
                $data[$key] = str_replace(array_keys($placeholders), array_values($placeholders), $value);
            } elseif (is_array($value)) {
                $data[$key] = $this->replacePlaceholdersInArray($value, $placeholders);
            }
        }

        return $data;
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
