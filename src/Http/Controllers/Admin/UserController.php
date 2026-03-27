<?php

namespace Templite\Cms\Http\Controllers\Admin;

use Illuminate\Routing\Controller;
use Templite\Cms\Http\CmsResponse;
use Templite\Cms\Models\User;
use Templite\Cms\Models\UserType;
use Templite\Cms\Services\GuardRegistry;

class UserController extends Controller
{
    /**
     * Список пользователей сайта.
     * Экран: Users/Index
     */
    public function index()
    {
        return CmsResponse::page('packages/templite/cms/resources/js/entries/users-index.js', [
            'users' => User::with(['userType', 'avatar'])->orderByDesc('created_at')->limit(100)->get(),
            'userTypes' => UserType::withCount('users')->orderBy('name')->get(),
            'guards' => app(GuardRegistry::class)->getOptions(),
        ], ['title' => 'Пользователи сайта']);
    }

}
