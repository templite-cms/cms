<?php

namespace Templite\Cms\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Templite\Cms\Http\Resources\BlockActionResource;
use Templite\Cms\Models\Block;
use Templite\Cms\Models\BlockAction;

class BlockActionController extends Controller
{
    /** @OA\Get(path="/blocks/{id}/actions", summary="Actions блока", tags={"Block Actions"}, security={{"bearerAuth":{}}}, @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")), @OA\Response(response=200, description="Actions")) */
    public function index(int $id): JsonResponse
    {
        Block::findOrFail($id);
        $actions = BlockAction::where('block_id', $id)->with('action')->orderBy('order')->get();
        return $this->success(BlockActionResource::collection($actions));
    }

    /** @OA\Post(path="/blocks/{id}/actions", summary="Привязать action к блоку", tags={"Block Actions"}, security={{"bearerAuth":{}}}, @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")), @OA\RequestBody(required=true, @OA\JsonContent(required={"action_id"}, @OA\Property(property="action_id", type="integer"), @OA\Property(property="params", type="object"))), @OA\Response(response=201, description="Привязано")) */
    public function store(Request $request, int $id): JsonResponse
    {
        Block::findOrFail($id);
        $data = $request->validate([
            'action_id' => 'required|integer|exists:actions,id',
            'params' => 'nullable|array', 'order' => 'integer',
        ]);
        $data['block_id'] = $id;
        if (!isset($data['order'])) {
            $data['order'] = BlockAction::where('block_id', $id)->max('order') + 1;
        }
        $ba = BlockAction::create($data);

        $this->logAction('create', 'block_action', $ba->id, ['block_id' => $id, 'action_id' => $data['action_id']]);

        return $this->success(new BlockActionResource($ba->load('action')), 'Action привязан.', 201);
    }

    /** @OA\Put(path="/block-actions/{id}", summary="Обновить параметры", tags={"Block Actions"}, security={{"bearerAuth":{}}}, @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")), @OA\Response(response=200, description="Обновлено")) */
    public function update(Request $request, int $id): JsonResponse
    {
        $ba = BlockAction::findOrFail($id);
        $ba->update($request->validate(['params' => 'nullable|array', 'order' => 'integer']));

        $this->logAction('update', 'block_action', $ba->id, ['block_id' => $ba->block_id]);

        return $this->success(new BlockActionResource($ba->fresh('action')));
    }

    /** @OA\Delete(path="/block-actions/{id}", summary="Отвязать action", tags={"Block Actions"}, security={{"bearerAuth":{}}}, @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")), @OA\Response(response=200, description="Отвязано")) */
    public function destroy(int $id): JsonResponse
    {
        $ba = BlockAction::findOrFail($id);
        $blockId = $ba->block_id;
        $actionId = $ba->action_id;
        $ba->delete();

        $this->logAction('delete', 'block_action', $id, ['block_id' => $blockId, 'action_id' => $actionId]);

        return $this->success(null, 'Action отвязан.');
    }
}
