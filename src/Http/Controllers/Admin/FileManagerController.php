<?php

namespace Templite\Cms\Http\Controllers\Admin;

use Illuminate\Routing\Controller;
use Templite\Cms\Http\CmsResponse;

class FileManagerController extends Controller
{
    /**
     * Файловый менеджер — управление файлами в public/.
     */
    public function index()
    {
        return CmsResponse::page('packages/templite/cms/resources/js/entries/file-manager-index.js', [], ['title' => 'Файловый менеджер']);
    }
}
