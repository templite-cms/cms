<?php

namespace Templite\Cms\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Templite\Cms\Dashboard\DashboardWidgetRegistry;

class DashboardWidgetController extends Controller
{
    /** @OA\Get(path="/dashboard/widgets/{slug}", summary="Данные виджета дашборда (фильтр, следующая страница)", tags={"Dashboard"}, security={{"bearerAuth":{}}}, @OA\Parameter(name="slug", in="path", required=true, @OA\Schema(type="string")), @OA\Parameter(name="filter", in="query", @OA\Schema(type="string")), @OA\Parameter(name="cursor", in="query", @OA\Schema(type="integer")), @OA\Response(response=200, description="Данные виджета"), @OA\Response(response=403, description="Нет права"), @OA\Response(response=404, description="Виджет не найден"), @OA\Response(response=500, description="Виджет упал при построении данных")) */
    public function show(Request $request, string $slug, DashboardWidgetRegistry $registry): JsonResponse
    {
        $manager = $request->user();
        $widget = $registry->find($slug);

        if ($widget === null) {
            return $this->error('Виджет не найден.', 404);
        }

        if (! $registry->allows($widget, $manager)) {
            return $this->error('Нет доступа к виджету.', 403);
        }

        // Сбой виджета (например, модульного) не должен отдавать голый 500 без логов
        try {
            return $this->success($widget->data($manager, $request->only(['filter', 'cursor'])));
        } catch (\Throwable $e) {
            report($e);

            return $this->error('Не удалось загрузить виджет.', 500);
        }
    }
}
