<?php

namespace Templite\Cms\Http\Controllers\Admin;

use Illuminate\Routing\Controller;
use Inertia\Inertia;
use Inertia\Response;
use Templite\Cms\Models\Manager;
use Templite\Cms\Models\ManagerType;

class ManagerController extends Controller
{
    /**
     * Единая страница менеджеров: типы + менеджеры.
     * Экран: Managers/Index
     */
    public function index(): Response
    {
        return Inertia::render('Managers/Index', [
            'managers' => Manager::with(['managerType', 'avatar'])
                ->orderBy('name')
                ->get(),
            'managerTypes' => ManagerType::withCount('managers')
                ->orderBy('name')
                ->get(),
            'allPermissions' => ManagerType::getAvailablePermissions(),
        ]);
    }
}
