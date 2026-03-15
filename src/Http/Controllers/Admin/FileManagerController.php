<?php

namespace Templite\Cms\Http\Controllers\Admin;

use Illuminate\Routing\Controller;
use Inertia\Inertia;
use Inertia\Response;

class FileManagerController extends Controller
{
    /**
     * Файловый менеджер — управление файлами в public/.
     */
    public function index(): Response
    {
        return Inertia::render('FileManager/Index');
    }
}
