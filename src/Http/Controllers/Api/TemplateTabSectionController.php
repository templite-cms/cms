<?php

namespace Templite\Cms\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Templite\Cms\Http\Resources\BlockSectionResource;
use Templite\Cms\Http\Resources\BlockTabResource;
use Templite\Cms\Models\TemplatePage;
use Templite\Cms\Services\FieldableService;

class TemplateTabSectionController extends Controller
{
    public function __construct(
        protected FieldableService $fieldableService
    ) {}

    /** @OA\Post(path="/templates/{templateId}/tabs", summary="Создать вкладку шаблона", tags={"Template Tabs & Sections"}, security={{"bearerAuth":{}}}, @OA\Parameter(name="templateId", in="path", required=true, @OA\Schema(type="integer")), @OA\RequestBody(required=true, @OA\JsonContent(required={"name"}, @OA\Property(property="name", type="string"), @OA\Property(property="order", type="integer"))), @OA\Response(response=201, description="Вкладка создана")) */
    public function storeTab(Request $request, int $templateId): JsonResponse
    {
        $template = TemplatePage::findOrFail($templateId);

        $data = $request->validate([
            'name' => 'required|string|max:255',
            'order' => 'integer',
        ]);

        $tab = $this->fieldableService->createTab($template, $data);

        $this->logAction('create', 'block_tab', $tab->id, ['name' => $tab->name, 'template_id' => $templateId]);

        return $this->success(
            new BlockTabResource($tab),
            'Вкладка создана.',
            201
        );
    }

    /** @OA\Put(path="/templates/{templateId}/tabs/reorder", summary="Изменить порядок вкладок шаблона", tags={"Template Tabs & Sections"}, security={{"bearerAuth":{}}}, @OA\Parameter(name="templateId", in="path", required=true, @OA\Schema(type="integer")), @OA\RequestBody(required=true, @OA\JsonContent(required={"ids"}, @OA\Property(property="ids", type="array", @OA\Items(type="integer")))), @OA\Response(response=200, description="Порядок обновлен")) */
    public function reorderTabs(Request $request, int $templateId): JsonResponse
    {
        $template = TemplatePage::findOrFail($templateId);

        $data = $request->validate([
            'ids' => 'required|array|min:1',
            'ids.*' => 'required|integer|exists:block_tabs,id',
        ]);

        $this->fieldableService->reorderTabs($template, $data['ids']);

        $this->logAction('reorder', 'block_tab', null, ['template_id' => $templateId]);

        return $this->success(null, 'Порядок обновлен.');
    }

    /** @OA\Post(path="/templates/{templateId}/sections", summary="Создать секцию шаблона", tags={"Template Tabs & Sections"}, security={{"bearerAuth":{}}}, @OA\Parameter(name="templateId", in="path", required=true, @OA\Schema(type="integer")), @OA\RequestBody(required=true, @OA\JsonContent(required={"name"}, @OA\Property(property="name", type="string"), @OA\Property(property="block_tab_id", type="integer", nullable=true), @OA\Property(property="order", type="integer"))), @OA\Response(response=201, description="Секция создана")) */
    public function storeSection(Request $request, int $templateId): JsonResponse
    {
        $template = TemplatePage::findOrFail($templateId);

        $data = $request->validate([
            'name' => 'required|string|max:255',
            'block_tab_id' => 'nullable|integer|exists:block_tabs,id',
            'order' => 'integer',
        ]);

        $section = $this->fieldableService->createSection($template, $data);

        $this->logAction('create', 'block_section', $section->id, ['name' => $section->name, 'template_id' => $templateId]);

        return $this->success(
            new BlockSectionResource($section),
            'Секция создана.',
            201
        );
    }

    /** @OA\Put(path="/templates/{templateId}/sections/reorder", summary="Изменить порядок секций шаблона", tags={"Template Tabs & Sections"}, security={{"bearerAuth":{}}}, @OA\Parameter(name="templateId", in="path", required=true, @OA\Schema(type="integer")), @OA\RequestBody(required=true, @OA\JsonContent(required={"ids"}, @OA\Property(property="ids", type="array", @OA\Items(type="integer")))), @OA\Response(response=200, description="Порядок обновлен")) */
    public function reorderSections(Request $request, int $templateId): JsonResponse
    {
        $template = TemplatePage::findOrFail($templateId);

        $data = $request->validate([
            'ids' => 'required|array|min:1',
            'ids.*' => 'required|integer|exists:block_sections,id',
        ]);

        $this->fieldableService->reorderSections($template, $data['ids']);

        $this->logAction('reorder', 'block_section', null, ['template_id' => $templateId]);

        return $this->success(null, 'Порядок обновлен.');
    }
}
