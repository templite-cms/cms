<?php

namespace Templite\Cms\Http\Controllers\Admin;

use Illuminate\Routing\Controller;
use Templite\Cms\Http\CmsResponse;
use Templite\Cms\Models\Language;

class LanguageController extends Controller
{
    /**
     * Единая страница языков.
     * Экран: Languages/Index
     */
    public function index()
    {
        return CmsResponse::page('packages/templite/cms/resources/js/entries/languages-index.js', [
            'languages' => Language::ordered()->get(),
        ], ['title' => 'Языки']);
    }
}
