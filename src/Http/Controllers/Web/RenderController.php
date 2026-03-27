<?php

namespace Templite\Cms\Http\Controllers\Web;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Templite\Cms\Models\City;
use Templite\Cms\Models\CityPage;
use Templite\Cms\Models\CmsConfig;
use Templite\Cms\Models\Page;
use Templite\Cms\Helpers\UrlHelper;
use Templite\Cms\Services\HandlerRegistry;
use Templite\Cms\Services\PageRenderer;

/**
 * Публичный рендер страниц CMS.
 *
 * Обрабатывает все запросы по URL, находит соответствующую страницу
 * и рендерит её через PageRenderer.
 *
 * При включённом мультигороде поддерживает виртуальные городские страницы:
 * /{city_slug}/contacts → рендерится из страницы-источника /contacts + оверрайды.
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
        // Если middleware вырезал языковой префикс — используем реальный путь
        if (app()->bound('locale_resolved_path')) {
            $url = app('locale_resolved_path');
        } else {
            $url = '/' . ltrim($url, '/');
        }

        $multicity = CmsConfig::getValue('multicity_enabled', false);

        // --- Мультигород: расширенная резолюция ---
        if ($multicity) {
            return $this->resolveWithMulticity($request, $url);
        }

        // --- Стандартная резолюция (без мультигорода) ---
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
     * Стандартная резолюция страницы (без мультигорода).
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
     * Резолюция с поддержкой мультигорода.
     *
     * Порядок:
     * 1. Exact match: материализованная или обычная страница
     * 2. Город из URL + stripped URL → city_source → виртуальная страница
     * 3. Нет города в URL + city_source → 302 на /{default_city}/url
     * 4. Нет города в URL + global → рендерим с текущим городом
     * 5. 404
     */
    protected function resolveWithMulticity(Request $request, string $url): Response|RedirectResponse
    {
        $cityFromUrl = app()->bound('city_from_url') ? app('city_from_url') : false;
        $city = app()->bound('current_city') ? app('current_city') : null;
        $strippedUrl = app()->bound('city_stripped_url') ? app('city_stripped_url') : null;

        // 1. Exact match по полному URL (материализованная или обычная страница)
        $page = Page::where('url', $url)->where('status', 1)->first();

        if ($page) {
            // Если это city_source при прямом заходе → редирект на город по умолчанию
            if ($page->isCitySource() && !$cityFromUrl) {
                $defaultCity = $city ?? City::getDefault();
                if ($defaultCity) {
                    return redirect('/' . $defaultCity->slug . $page->url, 302);
                }
            }

            return $this->renderPage($page, $request);
        }

        // 2. Город из URL + поиск страницы-источника по stripped URL
        if ($cityFromUrl && $strippedUrl && $city) {
            $sourcePage = Page::where('url', $strippedUrl)
                ->where('city_scope', Page::CITY_SCOPE_CITY_SOURCE)
                ->where('status', 1)
                ->first();

            if ($sourcePage) {
                return $this->renderVirtualCityPage($sourcePage, $city, $request);
            }
        }

        // 3. Нет города в URL, но есть city_source по этому URL → редирект
        if (!$cityFromUrl) {
            $sourcePage = Page::where('url', $url)
                ->where('city_scope', Page::CITY_SCOPE_CITY_SOURCE)
                ->where('status', 1)
                ->first();

            if ($sourcePage) {
                $defaultCity = $city ?? City::getDefault();
                if ($defaultCity) {
                    return redirect('/' . $defaultCity->slug . $url, 302);
                }
            }
        }

        // 4. Handler fallback — ищем handler-страницу как prefix
        $handlerUrl = $cityFromUrl && $strippedUrl ? $strippedUrl : $url;
        $handlerPage = $this->findHandlerPage($handlerUrl);
        if ($handlerPage) {
            $path = substr($handlerUrl, strlen($handlerPage->url) + 1);
            return $this->handlerRegistry->resolve($handlerPage->handler)
                ->handle($handlerPage, $path, $request);
        }

        // 5. Ничего не найдено → 404
        return $this->render404($request, $url);
    }

    /**
     * Рендер виртуальной городской страницы (источник + оверрайды).
     */
    protected function renderVirtualCityPage(Page $sourcePage, City $city, Request $request): Response
    {
        // Загружаем оверрайд (если есть)
        $cityPage = CityPage::where('city_id', $city->id)
            ->where('source_page_id', $sourcePage->id)
            ->with('blockOverrides')
            ->first();

        // Если материализована — редирект на реальную страницу
        if ($cityPage && $cityPage->is_materialized && $cityPage->materialized_page_id) {
            $materializedPage = Page::where('id', $cityPage->materialized_page_id)
                ->where('status', 1)
                ->first();

            if ($materializedPage) {
                return $this->renderPage($materializedPage, $request);
            }
        }

        // Проверяем оверрайд статуса
        if ($cityPage && $cityPage->status_override !== null && $cityPage->status_override !== 1) {
            return $this->render404($request, '/' . $city->slug . $sourcePage->url);
        }

        // Ensure assets are compiled
        if (!$sourcePage->asset) {
            app(\Templite\Cms\Services\PageAssetCompiler::class)->compile($sourcePage);
            $sourcePage->load('asset');
        }

        // Рендеринг виртуальной страницы
        $html = $this->pageRenderer->renderVirtualCityPage(
            $sourcePage,
            $city,
            $cityPage,
            $request
        );

        return response($html)
            ->header('Content-Type', 'text/html; charset=utf-8');
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
