<?php

namespace Templite\Cms\Http\Controllers\Admin;

use Illuminate\Routing\Controller;
use Templite\Cms\Http\CmsResponse;

class ExportImportController extends Controller
{
    public function index()
    {
        return CmsResponse::page('packages/templite/cms/resources/js/entries/export-import-index.js', [], ['title' => 'Импорт / Экспорт']);
    }
}
