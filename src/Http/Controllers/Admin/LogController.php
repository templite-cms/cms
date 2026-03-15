<?php

namespace Templite\Cms\Http\Controllers\Admin;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Inertia\Inertia;
use Inertia\Response;
use Templite\Cms\Models\Manager;
use Templite\Cms\Helpers\StringHelper;
use Templite\Cms\Models\ManagerLog;

class LogController extends Controller
{
    public function index(Request $request): Response
    {
        $query = ManagerLog::with('manager')->orderByDesc('created_at');

        if ($request->filled('manager_id')) {
            $query->where('manager_id', $request->input('manager_id'));
        }
        if ($request->filled('action')) {
            $query->where('action', $request->input('action'));
        }
        if ($request->filled('entity_type')) {
            $query->where('entity_type', $request->input('entity_type'));
        }
        if ($request->filled('search')) {
            $search = $request->input('search');
            $escaped = StringHelper::escapeLike($search);
            $query->where(function ($q) use ($escaped) {
                $q->where('entity_type', 'like', "%{$escaped}%")
                  ->orWhere('action', 'like', "%{$escaped}%")
                  ->orWhereHas('manager', fn ($mq) => $mq->where('name', 'like', "%{$escaped}%")
                      ->orWhere('login', 'like', "%{$escaped}%"));
            });
        }

        $perPage = min((int) $request->input('per_page', 30), 100);
        $logs = $query->paginate($perPage);

        return Inertia::render('Logs/Index', [
            'logs' => [
                'data' => $logs->map(fn ($log) => [
                    'id'           => $log->id,
                    'action'       => $log->action,
                    'entity_type'  => $log->entity_type,
                    'entity_id'    => $log->entity_id,
                    'entity_title' => $log->data['name'] ?? $log->data['title'] ?? $log->data['slug'] ?? null,
                    'details'      => $log->data,
                    'manager'      => $log->manager ? ['id' => $log->manager->id, 'name' => $log->manager->name ?? $log->manager->login] : null,
                    'ip'           => $log->ip,
                    'created_at'   => $log->created_at?->toIso8601String(),
                ]),
                'meta' => [
                    'current_page' => $logs->currentPage(),
                    'last_page'    => $logs->lastPage(),
                    'per_page'     => $logs->perPage(),
                    'total'        => $logs->total(),
                    'from'         => $logs->firstItem(),
                    'to'           => $logs->lastItem(),
                ],
            ],
            'filters' => $request->only(['search', 'manager_id', 'action', 'entity_type', 'sort_field', 'sort_order']),
            'managers' => Manager::orderBy('name')->get(['id', 'name', 'login']),
            'entityTypes' => [
                'page', 'page_block', 'page_type', 'page_type_attribute',
                'block', 'block_type', 'block_field', 'block_tab', 'block_section',
                'template', 'template_field',
                'action', 'block_action',
                'component', 'library',
                'file', 'file_folder',
                'manager', 'manager_type',
                'global_settings', 'core_settings', 'cache', 'auth',
            ],
            'actionTypes' => [
                'create', 'update', 'delete', 'copy', 'reorder',
                'upload', 'move', 'login', 'logout',
                'update_code', 'update_data', 'update_status',
                'compile', 'rebuild', 'clear_cache', 'invalidate_cache',
                'save_values',
            ],
        ]);
    }
}
