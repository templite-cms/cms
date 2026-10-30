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
     * Генерация XML-карты сайта.
     */
    public function index(): Response
    {
        $xml = $this->sitemapGenerator->generate();

        return response($xml)
            ->header('Content-Type', 'application/xml; charset=utf-8');
    }
}
