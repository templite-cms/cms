<?php

namespace Templite\Cms\Http\Controllers\Admin;

use Illuminate\Routing\Controller;
use Templite\Cms\Http\CmsResponse;
use Templite\Cms\Models\Library;

class LibraryController extends Controller
{
    /**
     * Список библиотек.
     * Экран: Libraries/Index
     */
    public function index()
    {
        return CmsResponse::page('packages/templite/cms/resources/js/entries/libraries-index.js', [
            'libraries' => Library::withCount(['blocks', 'templatePages', 'files'])
                ->orderBy('sort_order')
                ->orderBy('name')
                ->get(),
        ], ['title' => 'Библиотеки']);
    }
}
