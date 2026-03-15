<?php

namespace Templite\Cms\Http\Controllers\Admin;

use Illuminate\Routing\Controller;
use Inertia\Inertia;
use Inertia\Response;
use Templite\Cms\Models\ManagerType;

class ManagerTypeController extends Controller
{
    /**
     * Список типов менеджеров.
     * Экран: ManagerTypes/Index
     */
    public function index(): Response
    {
        return Inertia::render('ManagerTypes/Index', [
            'managerTypes' => ManagerType::withCount('managers')->orderBy('name')->get(),
        ]);
    }

    /**
     * Редактирование типа менеджера.
     * Экран: ManagerTypes/Edit
     */
    public function edit(int $id): Response
    {
        $type = ManagerType::withCount('managers')->findOrFail($id);

        return Inertia::render('ManagerTypes/Edit', [
            'managerType' => $type,
            'availablePermissions' => ManagerType::getAvailablePermissions(),
        ]);
    }

    /**
     * Создание нового типа менеджера.
     * Экран: ManagerTypes/Edit (пустая форма)
     */
    public function create(): Response
    {
        return Inertia::render('ManagerTypes/Edit', [
            'managerType' => null,
            'availablePermissions' => ManagerType::getAvailablePermissions(),
        ]);
    }

}
