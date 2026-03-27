<?php

namespace Templite\Cms\Http\Controllers\Admin;

use Illuminate\Routing\Controller;
use Templite\Cms\Http\CmsResponse;
use Templite\Cms\Models\ManagerType;

class ManagerTypeController extends Controller
{
    public function index()
    {
        return CmsResponse::page('packages/templite/cms/resources/js/entries/managers-index.js', [
            'managerTypes' => ManagerType::withCount('managers')->orderBy('name')->get(),
        ], ['title' => 'Типы менеджеров']);
    }

    public function edit(int $id)
    {
        $type = ManagerType::withCount('managers')->findOrFail($id);

        return CmsResponse::page('packages/templite/cms/resources/js/entries/managers-index.js', [
            'managerType' => $type,
            'availablePermissions' => ManagerType::getAvailablePermissions(),
        ], ['title' => $type->name]);
    }

    public function create()
    {
        return CmsResponse::page('packages/templite/cms/resources/js/entries/managers-index.js', [
            'managerType' => null,
            'availablePermissions' => ManagerType::getAvailablePermissions(),
        ], ['title' => 'Новый тип менеджера']);
    }
}
