<?php

namespace Templite\Cms\Http\Controllers\Admin;

use Illuminate\Routing\Controller;
use Inertia\Inertia;
use Inertia\Response;
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

        return Inertia::render('Profile/Index', [
            'manager' => $manager,
            'sessions' => $sessions,
        ]);
    }
}
