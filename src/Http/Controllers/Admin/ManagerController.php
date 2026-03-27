<?php

namespace Templite\Cms\Http\Controllers\Admin;

use Illuminate\Routing\Controller;
use Templite\Cms\Http\CmsResponse;
use Templite\Cms\Models\Manager;
use Templite\Cms\Models\ManagerType;

class ManagerController extends Controller
{
    /**
     * Единая страница менеджеров: типы + менеджеры.
     * Экран: Managers/Index
     */
    public function index()
    {
        return CmsResponse::page('packages/templite/cms/resources/js/entries/managers-index.js', [
            'managers' => Manager::with(['managerType', 'avatar'])
                ->orderBy('name')
                ->get(),
            'managerTypes' => ManagerType::withCount('managers')
                ->orderBy('name')
                ->get(),
            'allPermissions' => ManagerType::getAvailablePermissions(),
        ], ['title' => 'Менеджеры']);
    }
}
