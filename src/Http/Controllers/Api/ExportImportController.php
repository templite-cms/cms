<?php

namespace Templite\Cms\Http\Controllers\Api;

use Templite\Cms\Services\ImportExport\{ExportService, ImportService};
use Templite\Cms\Models\{Block, BlockType, TemplatePage, Component, Action, Library,
    BlockPreset, Page, PageType, GlobalFieldPage, CmsConfig, City, Language, ExportImportLog};
use Illuminate\Http\{JsonResponse, Request};
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * @OA\Tag(name="Export/Import", description="Экспорт и импорт сущностей CMS")
 */
class ExportImportController extends Controller
{
    /**
     * Маппинг ключей выбора фронтенда на классы моделей.
     *
     * @var array<string, class-string>
     */
    protected array $modelMap = [
        'blocks' => Block::class,
        'block_types' => BlockType::class,
        'templates' => TemplatePage::class,
        'components' => Component::class,
        'actions' => Action::class,
        'libraries' => Library::class,
        'presets' => BlockPreset::class,
        'pages' => Page::class,
        'page_types' => PageType::class,
        'global_field_pages' => GlobalFieldPage::class,
        'cms_config' => CmsConfig::class,
        'cities' => City::class,
        'languages' => Language::class,
    ];

    public function __construct(
        protected ExportService $exportService,
        protected ImportService $importService,
    ) {}

    /**
     * Список всех доступных сущностей для выбора при экспорте.
     *
     * @OA\Get(
     *     path="/export/entities",
     *     summary="Список сущностей для экспорта",
     *     tags={"Export/Import"},
     *     security={{"sanctumAuth":{}}},
     *     @OA\Response(response=200, description="Список сущностей по категориям")
     * )
     */
    public function entities(): JsonResponse
    {
        return $this->success([
            'blocks' => Block::select('id', 'name', 'slug', 'block_type_id')
                ->with('blockType:id,name,slug')
                ->orderBy('order')->get(),
            'templates' => TemplatePage::select('id', 'name', 'slug')
                ->orderBy('name')->get(),
            'components' => Component::select('id', 'name', 'slug')
                ->orderBy('name')->get(),
            'actions' => Action::select('id', 'name', 'slug')
                ->orderBy('name')->get(),
            'libraries' => Library::select('id', 'name', 'slug')
                ->orderBy('name')->get(),
            'presets' => BlockPreset::select('id', 'name', 'slug', 'block_id')
                ->orderBy('name')->get(),
            'pages' => Page::select('id', 'title', 'url', 'parent_id', 'status')
                ->orderBy('order')->get(),
            'page_types' => PageType::select('id', 'name', 'slug')
                ->orderBy('name')->get(),
            'global_field_pages' => GlobalFieldPage::select('id', 'name')
                ->orderBy('order')->get(),
            'block_types' => BlockType::select('id', 'name', 'slug')
                ->orderBy('order')->get(),
            'cities' => City::select('id', 'name', 'slug')
                ->orderBy('sort_order')->get(),
            'languages' => Language::select('id', 'name', 'code')
                ->orderBy('order')->get(),
        ]);
    }

    /**
     * Предварительный просмотр экспорта: резолв зависимостей, сводка.
     *
     * @OA\Post(
     *     path="/export/preview",
     *     summary="Превью экспорта с разрешением зависимостей",
     *     tags={"Export/Import"},
     *     security={{"sanctumAuth":{}}},
     *     @OA\RequestBody(required=true, @OA\JsonContent(
     *         required={"selected"},
     *         @OA\Property(property="selected", type="object",
     *             description="Объект: ключ -- тип сущности, значение -- массив идентификаторов",
     *             example={"blocks": {"hero-banner", "footer"}, "templates": {"default"}}
     *         )
     *     )),
     *     @OA\Response(response=200, description="Превью с зависимостями и сводкой")
     * )
     */
    public function preview(Request $request): JsonResponse
    {
        $selected = $request->validate([
            'selected' => 'required|array',
            'selected.*' => 'array',
        ]);

        $entities = $this->resolveSelectedEntities($selected['selected']);
        $result = $this->exportService->preview($entities);

        $grouped = [];
        foreach ($result['entities'] as $entity) {
            $grouped[$entity->getExportType()][] = [
                'identifier' => $entity->getExportIdentifier(),
                'name' => $entity->name ?? $entity->title ?? $entity->getExportIdentifier(),
            ];
        }

        return $this->success([
            'entities' => $grouped,
            'summary' => $result['summary'],
        ]);
    }

    /**
     * Выполнить экспорт и вернуть ZIP-файл.
     *
     * @OA\Post(
     *     path="/export/run",
     *     summary="Выполнить экспорт выбранных сущностей",
     *     tags={"Export/Import"},
     *     security={{"sanctumAuth":{}}},
     *     @OA\RequestBody(required=true, @OA\JsonContent(
     *         required={"selected"},
     *         @OA\Property(property="selected", type="object")
     *     )),
     *     @OA\Response(response=200, description="ZIP-файл с экспортированными данными",
     *         @OA\MediaType(mediaType="application/zip")
     *     )
     * )
     */
    public function run(Request $request): BinaryFileResponse
    {
        $selected = $request->validate([
            'selected' => 'required|array',
            'selected.*' => 'array',
        ]);

        $entities = $this->resolveSelectedEntities($selected['selected']);
        $resolved = $this->exportService->preview($entities);
        $zipPath = $this->exportService->export($resolved['entities']);

        $this->logAction('export', 'export_import', null, [
            'summary' => $resolved['summary'],
        ]);

        return response()->download($zipPath, basename($zipPath), [
            'Content-Type' => 'application/zip',
        ])->deleteFileAfterSend(false);
    }

    /**
     * Загрузить ZIP-файл для импорта: парсинг, обнаружение конфликтов.
     *
     * @OA\Post(
     *     path="/import/upload",
     *     summary="Загрузить ZIP для анализа перед импортом",
     *     tags={"Export/Import"},
     *     security={{"sanctumAuth":{}}},
     *     @OA\RequestBody(required=true,
     *         @OA\MediaType(mediaType="multipart/form-data",
     *             @OA\Schema(@OA\Property(property="file", type="string", format="binary"))
     *         )
     *     ),
     *     @OA\Response(response=200, description="Манифест, список сущностей и конфликты")
     * )
     */
    public function upload(Request $request): JsonResponse
    {
        $request->validate([
            'file' => 'required|file|mimes:zip|max:512000',
        ]);

        $file = $request->file('file');
        $path = $file->store('imports', 'local');
        $fullPath = Storage::disk('local')->path($path);

        $result = $this->importService->upload($fullPath);

        return $this->success([
            'path' => $path,
            'manifest' => $result['manifest'],
            'entities' => $this->formatEntitiesForFrontend($result['entities']),
            'conflicts' => $result['conflicts'],
        ]);
    }

    /**
     * Выполнить импорт из ранее загруженного ZIP-файла.
     *
     * @OA\Post(
     *     path="/import/run",
     *     summary="Выполнить импорт с указанными действиями по конфликтам",
     *     tags={"Export/Import"},
     *     security={{"sanctumAuth":{}}},
     *     @OA\RequestBody(required=true, @OA\JsonContent(
     *         required={"path"},
     *         @OA\Property(property="path", type="string", description="Путь к загруженному ZIP (из upload)"),
     *         @OA\Property(property="conflict_actions", type="object",
     *             description="Решения: 'type:identifier' => 'skip'|'overwrite'|'copy'",
     *             example={"block:hero-banner": "overwrite", "page:/about": "skip"}
     *         )
     *     )),
     *     @OA\Response(response=200, description="Статистика импорта (created, updated, skipped)")
     * )
     */
    public function importRun(Request $request): JsonResponse
    {
        $data = $request->validate([
            'path' => ['required', 'string', 'regex:/^imports\/[a-zA-Z0-9_\-]+\.zip$/'],
            'conflict_actions' => 'nullable|array',
        ]);

        $fullPath = Storage::disk('local')->path($data['path']);

        // Проверяем, что реальный путь находится внутри storage/app/imports/
        $allowedDir = realpath(Storage::disk('local')->path('imports'));
        $resolvedPath = realpath($fullPath);

        if ($allowedDir === false || $resolvedPath === false || !str_starts_with($resolvedPath, $allowedDir . DIRECTORY_SEPARATOR)) {
            return $this->error('Недопустимый путь к файлу импорта.', 403);
        }

        if (!file_exists($resolvedPath)) {
            return $this->error('Файл импорта не найден.', 404);
        }

        $stats = $this->importService->import($resolvedPath, $data['conflict_actions'] ?? []);

        $this->logAction('import', 'export_import', null, ['stats' => $stats]);

        return $this->success($stats, 'Импорт завершён.');
    }

    /**
     * История экспортов/импортов (пагинированная).
     *
     * @OA\Get(
     *     path="/export-import/log",
     *     summary="История операций экспорта/импорта",
     *     tags={"Export/Import"},
     *     security={{"sanctumAuth":{}}},
     *     @OA\Parameter(name="type", in="query", @OA\Schema(type="string", enum={"export", "import"})),
     *     @OA\Parameter(name="per_page", in="query", @OA\Schema(type="integer", default=20)),
     *     @OA\Response(response=200, description="Пагинированный список логов")
     * )
     */
    public function log(Request $request): JsonResponse
    {
        $query = ExportImportLog::with('manager')
            ->orderBy('created_at', 'desc');

        if ($request->has('type')) {
            $query->where('type', $request->type);
        }

        $perPage = min((int) $request->input('per_page', 20), 100);
        $logs = $query->paginate($perPage);

        return $this->success([
            'data' => $logs->items(),
            'meta' => [
                'current_page' => $logs->currentPage(),
                'last_page' => $logs->lastPage(),
                'from' => $logs->firstItem(),
                'to' => $logs->lastItem(),
                'total' => $logs->total(),
            ],
        ]);
    }

    /**
     * Скачать файл экспорта из истории.
     *
     * @OA\Get(
     *     path="/export-import/log/{id}/download",
     *     summary="Скачать файл экспорта из лога",
     *     tags={"Export/Import"},
     *     security={{"sanctumAuth":{}}},
     *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
     *     @OA\Response(response=200, description="ZIP-файл экспорта",
     *         @OA\MediaType(mediaType="application/zip")
     *     ),
     *     @OA\Response(response=400, description="Скачивание доступно только для экспортов"),
     *     @OA\Response(response=404, description="Файл не найден")
     * )
     */
    public function downloadLog(int $id): BinaryFileResponse|JsonResponse
    {
        $log = ExportImportLog::findOrFail($id);

        if ($log->type !== 'export') {
            return $this->error('Скачивание доступно только для экспортов.', 400);
        }

        $path = storage_path("app/exports/{$log->filename}");
        if (!file_exists($path)) {
            return $this->error('Файл не найден.', 404);
        }

        return response()->download($path);
    }

    /**
     * Преобразовать выбранные идентификаторы с фронтенда в массив Eloquent-моделей.
     *
     * @param array $selected ['blocks' => ['hero-banner', 'footer'], 'templates' => ['default'], ...]
     * @return \Templite\Cms\Contracts\Exportable[]
     */
    protected function resolveSelectedEntities(array $selected): array
    {
        $entities = [];

        foreach ($selected as $typeKey => $identifiers) {
            if (!isset($this->modelMap[$typeKey]) || empty($identifiers)) {
                continue;
            }

            $modelClass = $this->modelMap[$typeKey];

            $field = match ($typeKey) {
                'pages' => 'url',
                'cms_config' => 'key',
                'languages' => 'code',
                'global_field_pages' => 'name',
                default => 'slug',
            };

            $models = $modelClass::whereIn($field, $identifiers)->get();
            foreach ($models as $model) {
                $entities[] = $model;
            }
        }

        return $entities;
    }

    /**
     * Форматировать данные сущностей из ZIP для фронтенда.
     *
     * @param array $entitiesData ['block' => ['hero-banner' => [...], ...], ...]
     * @return array Плоский массив для отображения
     */
    protected function formatEntitiesForFrontend(array $entitiesData): array
    {
        $formatted = [];
        foreach ($entitiesData as $type => $items) {
            foreach ($items as $identifier => $data) {
                $formatted[] = [
                    'type' => $type,
                    'identifier' => $identifier,
                    'name' => $data['name'] ?? $data['title'] ?? $identifier,
                ];
            }
        }
        return $formatted;
    }
}
