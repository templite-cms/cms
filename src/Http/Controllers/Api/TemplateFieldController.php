<?php

namespace Templite\Cms\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Templite\Cms\Http\Requests\BlockField\ReorderBlockFieldsRequest;
use Templite\Cms\Http\Requests\TemplateField\StoreTemplateFieldRequest;
use Templite\Cms\Http\Requests\BlockField\UpdateBlockFieldRequest;
use Templite\Cms\Http\Resources\BlockFieldResource;
use Templite\Cms\Models\TemplatePage;
use Templite\Cms\Services\FieldableService;

class TemplateFieldController extends Controller
{
    public function __construct(
        protected FieldableService $fieldableService
    ) {}

    /** @OA\Get(path="/templates/{templateId}/fields", summary="Поля шаблона (дерево)", tags={"Template Fields"}, security={{"bearerAuth":{}}}, @OA\Parameter(name="templateId", in="path", required=true, @OA\Schema(type="integer")), @OA\Response(response=200, description="Дерево полей шаблона")) */
    public function index(int $templateId): JsonResponse
    {
        $template = TemplatePage::findOrFail($templateId);
        $fields = $this->fieldableService->getFieldsTree($template);

        return $this->success(BlockFieldResource::collection($fields));
    }

    /** @OA\Post(path="/templates/{templateId}/fields", summary="Создать поле шаблона", tags={"Template Fields"}, security={{"bearerAuth":{}}}, @OA\Parameter(name="templateId", in="path", required=true, @OA\Schema(type="integer")), @OA\RequestBody(required=true, @OA\JsonContent(required={"name","key","type"}, @OA\Property(property="name", type="string"), @OA\Property(property="key", type="string"), @OA\Property(property="type", type="string"))), @OA\Response(response=201, description="Поле создано")) */
    public function store(StoreTemplateFieldRequest $request, int $templateId): JsonResponse
    {
        $template = TemplatePage::findOrFail($templateId);
        $field = $this->fieldableService->createField($template, $request->validated());

        $this->logAction('create', 'template_field', $field->id, ['name' => $field->name, 'key' => $field->key, 'template_id' => $templateId]);

        return $this->success(
            new BlockFieldResource($field->load('children')),
            'Поле создано.',
            201
        );
    }

    /** @OA\Put(path="/templates/{templateId}/fields/reorder", summary="Изменить порядок полей шаблона", tags={"Template Fields"}, security={{"bearerAuth":{}}}, @OA\Parameter(name="templateId", in="path", required=true, @OA\Schema(type="integer")), @OA\RequestBody(required=true, @OA\JsonContent(required={"items"}, @OA\Property(property="items", type="array", @OA\Items(@OA\Property(property="id", type="integer"), @OA\Property(property="order", type="integer"))))), @OA\Response(response=200, description="Порядок обновлен")) */
    public function reorder(ReorderBlockFieldsRequest $request, int $templateId): JsonResponse
    {
        $template = TemplatePage::findOrFail($templateId);
        $this->fieldableService->reorderFields($template, $request->validated()['items']);

        $this->logAction('reorder', 'template_field', null, ['template_id' => $templateId]);

        return $this->success(null, 'Порядок обновлен.');
    }
}
