<?php

namespace Templite\Cms\Http\Controllers\Admin;

use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Templite\Cms\Http\CmsResponse;
use Templite\Cms\Models\ManagerSession;

class ProfileController extends Controller
{
    /**
     * Профиль текущего менеджера.
     * Экран: Profile/Index
     */
    public function index(): Response
    {
        $manager = auth('manager')->user();
        $manager->load(['managerType', 'avatar']);

        $sessions = ManagerSession::where('manager_id', $manager->id)
            ->orderByDesc('last_active')
            ->limit(20)
            ->get()
            ->map(fn ($session) => [
                'id' => $session->id,
                'ip' => $session->ip,
                'user_agent' => $session->user_agent,
                'last_active' => $session->last_active?->format('d.m.Y H:i'),
                'expires_at' => $session->expires_at?->format('d.m.Y H:i'),
                'is_current' => $session->token === hash('sha256', request()->session()->getId()),
            ]);

        return CmsResponse::page('packages/templite/cms/resources/js/entries/profile.js', [
            'manager' => $manager,
            'sessions' => $sessions,
        ], ['title' => 'Профиль']);
    }
}
