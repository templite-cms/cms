<?php

namespace Templite\Cms\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Templite\Cms\Http\Resources\BlockSectionResource;
use Templite\Cms\Http\Resources\BlockTabResource;
use Templite\Cms\Models\Block;
use Templite\Cms\Models\BlockField;
use Templite\Cms\Models\BlockSection;
use Templite\Cms\Models\BlockTab;
use Templite\Cms\Services\FieldableService;

class BlockTabSectionController extends Controller
{
    public function __construct(
        private readonly FieldableService $fieldableService,
    ) {
    }

    // =====================================================================
    // Tabs
    // =====================================================================

    /** @OA\Post(path="/blocks/{blockId}/tabs", summary="Создать вкладку блока", tags={"Block Tabs & Sections"}, security={{"bearerAuth":{}}}, @OA\Parameter(name="blockId", in="path", required=true, @OA\Schema(type="integer")), @OA\RequestBody(required=true, @OA\JsonContent(required={"name"}, @OA\Property(property="name", type="string"), @OA\Property(property="order", type="integer"))), @OA\Response(response=201, description="Вкладка создана"), @OA\Response(response=404, description="Блок не найден"), @OA\Response(response=422, description="Ошибка валидации")) */
    public function storeTab(Request $request, int $blockId): JsonResponse
    {
        $block = Block::findOrFail($blockId);

        $data = $request->validate([
            'name' => 'required|string|max:255',
            'order' => 'integer',
            'columns' => 'integer|min:1|max:4',
            'column_widths' => 'nullable|array|max:4',
            'column_widths.*' => 'numeric|min:10|max:100',
        ]);

        $tab = $this->fieldableService->createTab($block, $data);

        $this->logAction('create', 'block_tab', $tab->id, ['name' => $tab->name, 'block_id' => $blockId]);

        return $this->success(
            new BlockTabResource($tab),
            'Вкладка создана.',
            201
        );
    }

    /** @OA\Put(path="/block-tabs/{id}", summary="Обновить вкладку", tags={"Block Tabs & Sections"}, security={{"bearerAuth":{}}}, @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")), @OA\RequestBody(required=true, @OA\JsonContent(@OA\Property(property="name", type="string"), @OA\Property(property="order", type="integer"))), @OA\Response(response=200, description="Вкладка обновлена"), @OA\Response(response=404, description="Вкладка не найдена"), @OA\Response(response=422, description="Ошибка валидации")) */
    public function updateTab(Request $request, int $id): JsonResponse
    {
        $tab = BlockTab::findOrFail($id);
        $tab->update($request->validate([
            'name' => 'sometimes|string|max:255',
            'order' => 'integer',
            'columns' => 'integer|min:1|max:4',
            'column_widths' => 'nullable|array|max:4',
            'column_widths.*' => 'numeric|min:10|max:100',
        ]));

        $this->logAction('update', 'block_tab', $tab->id, ['name' => $tab->name]);

        return $this->success(new BlockTabResource($tab->fresh()));
    }

    /** @OA\Delete(path="/block-tabs/{id}", summary="Удалить вкладку", tags={"Block Tabs & Sections"}, security={{"bearerAuth":{}}}, @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")), @OA\Response(response=200, description="Вкладка удалена (поля и секции откреплены)"), @OA\Response(response=404, description="Вкладка не найдена")) */
    public function destroyTab(int $id): JsonResponse
    {
        $tab = BlockTab::findOrFail($id);
        $name = $tab->name;

        BlockField::where('block_tab_id', $tab->id)->update(['block_tab_id' => null]);
        BlockSection::where('block_tab_id', $tab->id)->update(['block_tab_id' => null]);
        $tab->delete();

        $this->logAction('delete', 'block_tab', $id, ['name' => $name]);

        return $this->success(null, 'Вкладка удалена.');
    }

    // =====================================================================
    // Sections
    // =====================================================================

    /** @OA\Post(path="/blocks/{blockId}/sections", summary="Создать секцию блока", tags={"Block Tabs & Sections"}, security={{"bearerAuth":{}}}, @OA\Parameter(name="blockId", in="path", required=true, @OA\Schema(type="integer")), @OA\RequestBody(required=true, @OA\JsonContent(required={"name"}, @OA\Property(property="name", type="string"), @OA\Property(property="block_tab_id", type="integer", nullable=true), @OA\Property(property="order", type="integer"))), @OA\Response(response=201, description="Секция создана"), @OA\Response(response=404, description="Блок не найден"), @OA\Response(response=422, description="Ошибка валидации")) */
    public function storeSection(Request $request, int $blockId): JsonResponse
    {
        $block = Block::findOrFail($blockId);

        $data = $request->validate([
            'name' => 'required|string|max:255',
            'block_tab_id' => 'nullable|integer|exists:block_tabs,id',
            'order' => 'integer',
            'column_index' => 'integer|min:0|max:3',
        ]);

        $section = $this->fieldableService->createSection($block, $data);

        $this->logAction('create', 'block_section', $section->id, ['name' => $section->name, 'block_id' => $blockId]);

        return $this->success(
            new BlockSectionResource($section),
            'Секция создана.',
            201
        );
    }

    /** @OA\Put(path="/block-sections/{id}", summary="Обновить секцию", tags={"Block Tabs & Sections"}, security={{"bearerAuth":{}}}, @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")), @OA\RequestBody(required=true, @OA\JsonContent(@OA\Property(property="name", type="string"), @OA\Property(property="block_tab_id", type="integer", nullable=true), @OA\Property(property="order", type="integer"))), @OA\Response(response=200, description="Секция обновлена"), @OA\Response(response=404, description="Секция не найдена"), @OA\Response(response=422, description="Ошибка валидации")) */
    public function updateSection(Request $request, int $id): JsonResponse
    {
        $section = BlockSection::findOrFail($id);
        $section->update($request->validate([
            'name' => 'sometimes|string|max:255',
            'block_tab_id' => 'nullable|integer',
            'order' => 'integer',
            'column_index' => 'integer|min:0|max:3',
        ]));

        $this->logAction('update', 'block_section', $section->id, ['name' => $section->name]);

        return $this->success(new BlockSectionResource($section->fresh()));
    }

    /** @OA\Delete(path="/block-sections/{id}", summary="Удалить секцию", tags={"Block Tabs & Sections"}, security={{"bearerAuth":{}}}, @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")), @OA\Response(response=200, description="Секция удалена (поля откреплены)"), @OA\Response(response=404, description="Секция не найдена")) */
    public function destroySection(int $id): JsonResponse
    {
        $section = BlockSection::findOrFail($id);
        $name = $section->name;

        BlockField::where('block_section_id', $section->id)->update(['block_section_id' => null]);
        $section->delete();

        $this->logAction('delete', 'block_section', $id, ['name' => $name]);

        return $this->success(null, 'Секция удалена.');
    }

    // =====================================================================
    // Reorder
    // =====================================================================

    /** @OA\Put(path="/blocks/{blockId}/tabs/reorder", summary="Изменить порядок вкладок блока", tags={"Block Tabs & Sections"}, security={{"bearerAuth":{}}}, @OA\Parameter(name="blockId", in="path", required=true, @OA\Schema(type="integer")), @OA\RequestBody(required=true, @OA\JsonContent(required={"ids"}, @OA\Property(property="ids", type="array", @OA\Items(type="integer")))), @OA\Response(response=200, description="Порядок обновлен"), @OA\Response(response=404, description="Блок не найден"), @OA\Response(response=422, description="Ошибка валидации")) */
    public function reorderTabs(Request $request, int $blockId): JsonResponse
    {
        $block = Block::findOrFail($blockId);

        $data = $request->validate([
            'ids' => 'required|array|min:1',
            'ids.*' => 'required|integer|exists:block_tabs,id',
        ]);

        $this->fieldableService->reorderTabs($block, $data['ids']);

        $this->logAction('reorder', 'block_tab', null, ['block_id' => $blockId]);

        return $this->success(null, 'Порядок обновлен.');
    }

    /** @OA\Put(path="/blocks/{blockId}/sections/reorder", summary="Изменить порядок секций блока", tags={"Block Tabs & Sections"}, security={{"bearerAuth":{}}}, @OA\Parameter(name="blockId", in="path", required=true, @OA\Schema(type="integer")), @OA\RequestBody(required=true, @OA\JsonContent(required={"ids"}, @OA\Property(property="ids", type="array", @OA\Items(type="integer")))), @OA\Response(response=200, description="Порядок обновлен"), @OA\Response(response=404, description="Блок не найден"), @OA\Response(response=422, description="Ошибка валидации")) */
    public function reorderSections(Request $request, int $blockId): JsonResponse
    {
        $block = Block::findOrFail($blockId);

        if ($request->has('items')) {
            $data = $request->validate([
                'items' => 'required|array|min:1',
                'items.*.id' => 'required|integer|exists:block_sections,id',
                'items.*.order' => 'required|integer',
                'items.*.column_index' => 'integer|min:0|max:3',
            ]);

            foreach ($data['items'] as $item) {
                $section = BlockSection::find($item['id']);
                if ($section) {
                    $section->order = $item['order'];
                    if (isset($item['column_index'])) {
                        $section->column_index = $item['column_index'];
                    }
                    $section->save();
                }
            }
        } else {
            $data = $request->validate([
                'ids' => 'required|array|min:1',
                'ids.*' => 'required|integer|exists:block_sections,id',
            ]);

            $this->fieldableService->reorderSections($block, $data['ids']);
        }

        $this->logAction('reorder', 'block_section', null, ['block_id' => $blockId]);

        return $this->success(null, 'Порядок обновлен.');
    }
}
