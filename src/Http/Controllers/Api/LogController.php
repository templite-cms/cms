<?php

namespace Templite\Cms\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Templite\Cms\Http\Resources\ManagerLogResource;
use Templite\Cms\Models\ManagerLog;

class LogController extends Controller
{
    /** @OA\Get(path="/logs", summary="Логи действий", tags={"Logs"}, security={{"bearerAuth":{}}}, @OA\Parameter(name="manager_id", in="query", @OA\Schema(type="integer")), @OA\Parameter(name="entity_type", in="query", @OA\Schema(type="string")), @OA\Parameter(name="action", in="query", @OA\Schema(type="string")), @OA\Parameter(name="per_page", in="query", @OA\Schema(type="integer")), @OA\Response(response=200, description="Логи")) */
    public function index(Request $request): JsonResponse
    {
        $query = ManagerLog::with('manager');

        if ($request->has('manager_id')) $query->where('manager_id', $request->manager_id);
        if ($request->has('entity_type')) $query->where('entity_type', $request->entity_type);
        if ($request->has('action')) $query->where('action', $request->action);

        $perPage = min((int) $request->input('per_page', 50), 100);
        $logs = $query->orderByDesc('created_at')->paginate($perPage);

        return $this->success([
            'data' => ManagerLogResource::collection($logs),
            'meta' => [
                'current_page' => $logs->currentPage(),
                'last_page' => $logs->lastPage(),
                'per_page' => $logs->perPage(),
                'total' => $logs->total(),
            ],
        ]);
    }
}
