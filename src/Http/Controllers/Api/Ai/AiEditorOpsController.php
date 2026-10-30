<?php

namespace Templite\Cms\Http\Controllers\Api\Ai;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Templite\Cms\Http\Controllers\Api\Controller;
use Templite\Cms\Models\AiEditorOp;
use Templite\Cms\Services\Ai\Runs\EditorOpStore;

/** Правки агента, которые ждут открытия своего редактора. */
class AiEditorOpsController extends Controller
{
    public function index(Request $request, EditorOpStore $ops): JsonResponse
    {
        $data = $request->validate([
            'target_type' => ['required', 'string', 'max:32'],
            'target_id' => ['required', 'string', 'max:64'],
        ]);

        $list = $ops->pendingFor($this->managerId(), $data['target_type'], $data['target_id'])
            ->map(fn (AiEditorOp $op) => ['id' => $op->id, 'op' => $op->op])
            ->values();

        return $this->success($list);
    }

    /** Редактор забирает правку перед применением; claimed=false — её уже применили. */
    public function claim(int $id, EditorOpStore $ops): JsonResponse
    {
        return $this->success(['claimed' => $ops->claim($id, $this->managerId())]);
    }

    private function managerId(): int
    {
        $user = auth()->user() ?? auth('manager')->user();

        return (int) ($user?->id ?? 0);
    }
}
