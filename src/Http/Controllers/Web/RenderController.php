<?php

namespace Templite\Cms\Http\Controllers\Web;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Templite\Cms\Models\Page;
use Templite\Cms\Helpers\UrlHelper;
use Templite\Cms\Services\HandlerRegistry;
use Templite\Cms\Services\PageRenderer;

/**
 * Публичный рендер страниц CMS.
 *
 * Обрабатывает все запросы по URL, находит соответствующую страницу
 * и рендерит её через PageRenderer.
 */
class RenderController extends Controller
{
    public function __construct(
        protected PageRenderer $pageRenderer,
        protected HandlerRegistry $handlerRegistry,
    ) {}

    /**
     * Рендер страницы по URL.
     * Catch-all роут: любой URL, не совпавший с другими маршрутами.
     */
    public function page(Request $request, string $url = ''): Response|RedirectResponse
    {
        $url = '/' . ltrim($url, '/');

        return $this->resolveStandard($request, $url);
    }

    /**
     * Рендер главной страницы.
     */
    public function home(Request $request): Response
    {
        return $this->page($request, '/');
    }

    /**
     * Резолюция страницы: точное совпадение URL, затем handler-страница по префиксу, затем 404.
     */
    protected function resolveStandard(Request $request, string $url): Response|RedirectResponse
    {
        $page = Page::where('url', $url)
            ->where('status', 1)
            ->first();

        // Если страница найдена и имеет handler — делегируем обработку
        if ($page && $page->handler && $this->handlerRegistry->has($page->handler)) {
            return $this->handlerRegistry->resolve($page->handler)
                ->handle($page, '', $request);
        }

        if ($page) {
            return $this->renderPage($page, $request);
        }

        // Не нашли точное совпадение — ищем handler-страницу как prefix
        $handlerPage = $this->findHandlerPage($url);
        if ($handlerPage) {
            $path = substr($url, strlen($handlerPage->url) + 1);
            return $this->handlerRegistry->resolve($handlerPage->handler)
                ->handle($handlerPage, $path, $request);
        }

        return $this->render404($request, $url);
    }

    /**
     * Рендер обычной страницы.
     */
    protected function renderPage(Page $page, Request $request): Response|RedirectResponse
    {
        // Проверка на редирект (если задан)
        if (!empty($page->seo_data['redirect_url'])) {
            $redirectUrl = $page->seo_data['redirect_url'];

            if (!UrlHelper::isInternalUrl($redirectUrl)) {
                \Illuminate\Support\Facades\Log::warning('Open Redirect заблокирован', [
                    'page_id' => $page->id,
                    'page_url' => $page->url,
                    'redirect_url' => $redirectUrl,
                ]);

                return redirect('/');
            }

            $code = $page->seo_data['redirect_code'] ?? 301;
            return redirect($redirectUrl, $code);
        }

        // Ensure assets are compiled (fallback for pages without pre-compiled assets)
        if (!$page->asset) {
            app(\Templite\Cms\Services\PageAssetCompiler::class)->compile($page);
            $page->load('asset');
        }

        // Рендеринг страницы
        $html = $this->pageRenderer->render($page, $request);

        return response($html)
            ->header('Content-Type', 'text/html; charset=utf-8');
    }

    /**
     * Найти handler-страницу, чей URL является префиксом запрошенного URL.
     *
     * Например: URL='/blog/my-article', ищем страницу с url='/blog' и handler IS NOT NULL.
     * Возвращает наиболее специфичный match (самый длинный URL).
     */
    protected function findHandlerPage(string $url): ?Page
    {
        $segments = explode('/', trim($url, '/'));
        if (empty($segments) || $segments[0] === '') {
            return null;
        }

        $prefixes = [];
        $current = '';
        foreach ($segments as $segment) {
            $current .= '/' . $segment;
            $prefixes[] = $current;
        }

        return Page::whereIn('url', array_reverse($prefixes))
            ->whereNotNull('handler')
            ->where('status', 1)
            ->orderByRaw('LENGTH(url) DESC')
            ->first();
    }

    /**
     * Страница 404.
     */
    protected function render404(Request $request, string $url): Response
    {
        // Пытаемся найти страницу 404 в CMS
        $page404 = Page::where('alias', '404')
            ->where('status', 1)
            ->first();

        if ($page404) {
            $html = $this->pageRenderer->render($page404, $request);
            return response($html, 404)
                ->header('Content-Type', 'text/html; charset=utf-8');
        }

        // Стандартный 404
        abort(404);
    }
}
