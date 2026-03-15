<?php

namespace Templite\Cms\Http\Controllers\Admin;

use Illuminate\Routing\Controller;
use Inertia\Inertia;
use Inertia\Response;
use Templite\Cms\Models\City;

class CityController extends Controller
{
    /**
     * Единая страница городов.
     * Экран: Cities/Index
     */
    public function index(): Response
    {
        return Inertia::render('Cities/Index', [
            'cities' => City::ordered()->get(),
        ]);
    }
}
