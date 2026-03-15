<?php

namespace Templite\Cms\Http\Controllers\Web;

use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Templite\Cms\Services\SitemapGenerator;

/**
 * Контроллер XML Sitemap.
 */
class SitemapController extends Controller
{
    public function __construct(protected SitemapGenerator $sitemapGenerator) {}

    /**
     * Генерация XML-карты сайта (или sitemap index при мультигороде).
     */
    public function index(): Response
    {
        $xml = $this->sitemapGenerator->generate();

        return response($xml)
            ->header('Content-Type', 'application/xml; charset=utf-8');
    }

    /**
     * Sitemap для глобальных страниц.
     */
    public function global(): Response
    {
        $xml = $this->sitemapGenerator->generateGlobal();

        return response($xml)
            ->header('Content-Type', 'application/xml; charset=utf-8');
    }

    /**
     * Sitemap для конкретного города.
     */
    public function city(string $slug): Response
    {
        $xml = $this->sitemapGenerator->generateForCity($slug);

        if ($xml === null) {
            abort(404);
        }

        return response($xml)
            ->header('Content-Type', 'application/xml; charset=utf-8');
    }
}
