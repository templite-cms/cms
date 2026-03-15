<?php

namespace Templite\Cms\Http\Controllers\Admin;

use Illuminate\Routing\Controller;
use Inertia\Inertia;
use Inertia\Response;

class ExportImportController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('cms::ExportImport/Index');
    }
}
