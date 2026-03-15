<?php

namespace Templite\Cms\Http\Controllers\Admin;

use Illuminate\Routing\Controller;
use Inertia\Inertia;
use Inertia\Response;
use Templite\Cms\Models\Language;

class LanguageController extends Controller
{
    /**
     * Единая страница языков.
     * Экран: Languages/Index
     */
    public function index(): Response
    {
        return Inertia::render('Languages/Index', [
            'languages' => Language::ordered()->get(),
        ]);
    }
}
