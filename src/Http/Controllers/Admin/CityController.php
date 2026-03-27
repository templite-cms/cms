<?php

namespace Templite\Cms\Http\Controllers\Admin;

use Illuminate\Routing\Controller;
use Templite\Cms\Http\CmsResponse;
use Templite\Cms\Models\City;

class CityController extends Controller
{
    /**
     * Единая страница городов.
     * Экран: Cities/Index
     */
    public function index()
    {
        return CmsResponse::page('packages/templite/cms/resources/js/entries/cities-index.js', [
            'cities' => City::ordered()->get(),
        ], ['title' => 'Города']);
    }
}
