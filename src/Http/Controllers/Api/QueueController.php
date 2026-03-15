<?php

namespace Templite\Cms\Http\Controllers\Api;

use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use OpenApi\Annotations as OA;
use Templite\Cms\Models\Queue;

class QueueController extends Controller
{
    /**
     * @OA\Get(
     *     path="/core-settings/queues/stats",
     *     summary="Статистика очередей",
     *     tags={"CoreSettings / Queues"},
     *     security={{"sanctumAuth":{}}},
     *     @OA\Response(
     *         response=200,
     *         description="Статистика очередей",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean"),
     *             @OA\Property(property="data", type="object",
     *                 @OA\Property(property="queues", type="object"),
     *                 @OA\Property(property="processed", type="object",
     *                     @OA\Property(property="last_hour", type="integer"),
     *                     @OA\Property(property="last_24h", type="integer")
     *                 )
     *             )
     *         )
     *     )
     * )
     */
    public function stats(): JsonResponse
    {
        $defaultQueues = \Templite\Cms\Models\Queue::activeNames();

        $activeQueues = DB::table('jobs')
            ->select('queue')
            ->distinct()
            ->pluck('queue')
            ->merge(
                DB::table('failed_jobs')
                    ->select('queue')
                    ->distinct()
                    ->pluck('queue')
            )
            ->merge($defaultQueues)
            ->unique()
            ->values();

        $pendingCounts = DB::table('jobs')
            ->select('queue', DB::raw('count(*) as cnt'))
            ->groupBy('queue')
            ->pluck('cnt', 'queue');

        $failedCounts = DB::table('failed_jobs')
            ->select('queue', DB::raw('count(*) as cnt'))
            ->groupBy('queue')
            ->pluck('cnt', 'queue');

        $queues = [];
        foreach ($activeQueues as $queue) {
            $queues[$queue] = [
                'pending' => (int) ($pendingCounts[$queue] ?? 0),
                'failed' => (int) ($failedCounts[$queue] ?? 0),
            ];
        }

        $hourKey = 'cms:queue_stats:processed:' . date('Y-m-d-H');
        $dayKey = 'cms:queue_stats:processed:' . date('Y-m-d');

        return $this->success([
            'queues' => $queues,
            'paused' => (bool) Cache::get('cms:queue:paused', false),
            'processed' => [
                'last_hour' => (int) Cache::get($hourKey, 0),
                'last_24h' => (int) Cache::get($dayKey, 0),
            ],
        ]);
    }

    /**
     * @OA\Get(
     *     path="/core-settings/queues/failed",
     *     summary="Список проваленных задач",
     *     tags={"CoreSettings / Queues"},
     *     security={{"sanctumAuth":{}}},
     *     @OA\Parameter(name="queue", in="query", required=false, @OA\Schema(type="string")),
     *     @OA\Parameter(name="page", in="query", required=false, @OA\Schema(type="integer")),
     *     @OA\Parameter(name="per_page", in="query", required=false, @OA\Schema(type="integer")),
     *     @OA\Response(response=200, description="Список проваленных задач")
     * )
     */
    public function failed(Request $request): JsonResponse
    {
        $perPage = min((int) $request->input('per_page', 15), 100);

        $query = DB::table('failed_jobs')->orderByDesc('failed_at');

        if ($queue = $request->input('queue')) {
            $query->where('queue', $queue);
        }

        $paginated = $query->paginate($perPage);

        $data = collect($paginated->items())->map(function ($job) {
            $payload = json_decode($job->payload, true);
            return [
                'id' => $job->uuid,
                'queue' => $job->queue,
                'job_class' => $payload['displayName'] ?? 'Unknown',
                'exception_short' => mb_substr($job->exception, 0, 200),
                'exception_full' => $job->exception,
                'payload' => $payload,
                'failed_at' => Carbon::parse($job->failed_at)->toIso8601String(),
            ];
        });

        return $this->success([
            'data' => $data,
            'meta' => [
                'current_page' => $paginated->currentPage(),
                'last_page' => $paginated->lastPage(),
                'per_page' => $paginated->perPage(),
                'total' => $paginated->total(),
            ],
        ]);
    }

    /**
     * @OA\Post(
     *     path="/core-settings/queues/failed/{id}/retry",
     *     summary="Повторить проваленную задачу",
     *     tags={"CoreSettings / Queues"},
     *     security={{"sanctumAuth":{}}},
     *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="string")),
     *     @OA\Response(response=200, description="Задача поставлена в очередь повторно")
     * )
     */
    public function retry(string $id): JsonResponse
    {
        $exists = DB::table('failed_jobs')->where('uuid', $id)->exists();
        if (!$exists) {
            return $this->error('Задача не найдена', 404);
        }

        Artisan::call('queue:retry', ['id' => [$id]]);

        $this->logAction('queue.retry', 'failed_job', null, ['uuid' => $id]);

        return $this->success(null, 'Задача поставлена в очередь повторно');
    }

    /**
     * @OA\Delete(
     *     path="/core-settings/queues/failed/{id}",
     *     summary="Удалить проваленную задачу",
     *     tags={"CoreSettings / Queues"},
     *     security={{"sanctumAuth":{}}},
     *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="string")),
     *     @OA\Response(response=200, description="Задача удалена")
     * )
     */
    public function destroy(string $id): JsonResponse
    {
        $exists = DB::table('failed_jobs')->where('uuid', $id)->exists();
        if (!$exists) {
            return $this->error('Задача не найдена', 404);
        }

        Artisan::call('queue:forget', ['id' => $id]);

        $this->logAction('queue.delete', 'failed_job', null, ['uuid' => $id]);

        return $this->success(null, 'Задача удалена');
    }

    /**
     * @OA\Post(
     *     path="/core-settings/queues/failed/retry-all",
     *     summary="Повторить все проваленные задачи",
     *     tags={"CoreSettings / Queues"},
     *     security={{"sanctumAuth":{}}},
     *     @OA\RequestBody(
     *         @OA\JsonContent(
     *             @OA\Property(property="queue", type="string", nullable=true)
     *         )
     *     ),
     *     @OA\Response(response=200, description="Задачи поставлены в очередь повторно")
     * )
     */
    public function retryAll(Request $request): JsonResponse
    {
        $queue = $request->input('queue');

        if ($queue) {
            $ids = DB::table('failed_jobs')
                ->where('queue', $queue)
                ->pluck('uuid')
                ->toArray();

            if (empty($ids)) {
                return $this->success(['count' => 0], 'Нет задач для повтора');
            }

            Artisan::call('queue:retry', ['id' => $ids]);
            $count = count($ids);
        } else {
            $count = DB::table('failed_jobs')->count();
            Artisan::call('queue:retry', ['id' => ['all']]);
        }

        $this->logAction('queue.retry_all', 'failed_jobs', null, ['queue' => $queue, 'count' => $count]);

        return $this->success(['count' => $count], "Повторено задач: {$count}");
    }

    /**
     * @OA\Post(
     *     path="/core-settings/queues/failed/flush",
     *     summary="Удалить все проваленные задачи",
     *     tags={"CoreSettings / Queues"},
     *     security={{"sanctumAuth":{}}},
     *     @OA\RequestBody(
     *         @OA\JsonContent(
     *             @OA\Property(property="queue", type="string", nullable=true)
     *         )
     *     ),
     *     @OA\Response(response=200, description="Задачи удалены")
     * )
     */
    public function flush(Request $request): JsonResponse
    {
        $queue = $request->input('queue');

        if ($queue) {
            $count = DB::table('failed_jobs')->where('queue', $queue)->count();
            DB::table('failed_jobs')->where('queue', $queue)->delete();
        } else {
            $count = DB::table('failed_jobs')->count();
            Artisan::call('queue:flush');
        }

        $this->logAction('queue.flush', 'failed_jobs', null, ['queue' => $queue, 'count' => $count]);

        return $this->success(['count' => $count], "Удалено задач: {$count}");
    }

    /**
     * @OA\Post(
     *     path="/core-settings/queues/restart",
     *     summary="Перезапустить воркеры очередей",
     *     tags={"CoreSettings / Queues"},
     *     security={{"sanctumAuth":{}}},
     *     @OA\Response(response=200, description="Сигнал перезапуска отправлен")
     * )
     */
    public function restart(): JsonResponse
    {
        Artisan::call('queue:restart');

        $this->logAction('queue.restart', 'queue', null);

        return $this->success(null, 'Сигнал перезапуска отправлен воркерам');
    }

    /**
     * @OA\Post(
     *     path="/core-settings/queues/pause",
     *     summary="Приостановить обработку очередей",
     *     tags={"CoreSettings / Queues"},
     *     security={{"sanctumAuth":{}}},
     *     @OA\Response(response=200, description="Обработка приостановлена")
     * )
     */
    public function pause(): JsonResponse
    {
        Cache::put('cms:queue:paused', true);

        $this->logAction('queue.pause', 'queue', null);

        return $this->success(null, 'Обработка очередей приостановлена');
    }

    /**
     * @OA\Post(
     *     path="/core-settings/queues/resume",
     *     summary="Возобновить обработку очередей",
     *     tags={"CoreSettings / Queues"},
     *     security={{"sanctumAuth":{}}},
     *     @OA\Response(response=200, description="Обработка возобновлена")
     * )
     */
    public function resume(): JsonResponse
    {
        Cache::forget('cms:queue:paused');

        $this->logAction('queue.resume', 'queue', null);

        return $this->success(null, 'Обработка очередей возобновлена');
    }

    /**
     * @OA\Get(
     *     path="/core-settings/queues/manage",
     *     summary="Список очередей (CRUD)",
     *     tags={"CoreSettings / Queues"},
     *     security={{"sanctumAuth":{}}},
     *     @OA\Response(response=200, description="Список очередей")
     * )
     */
    public function listManaged(): JsonResponse
    {
        $queues = Queue::ordered()->get();

        $pendingCounts = DB::table('jobs')
            ->select('queue', DB::raw('count(*) as cnt'))
            ->groupBy('queue')
            ->pluck('cnt', 'queue');

        $failedCounts = DB::table('failed_jobs')
            ->select('queue', DB::raw('count(*) as cnt'))
            ->groupBy('queue')
            ->pluck('cnt', 'queue');

        $data = $queues->map(fn ($q) => [
            'id' => $q->id,
            'name' => $q->name,
            'priority' => $q->priority,
            'tries' => $q->tries,
            'timeout' => $q->timeout,
            'sleep' => $q->sleep,
            'process_via_schedule' => $q->process_via_schedule,
            'is_active' => $q->is_active,
            'pending_count' => (int) ($pendingCounts[$q->name] ?? 0),
            'failed_count' => (int) ($failedCounts[$q->name] ?? 0),
        ]);

        return $this->success($data);
    }

    /**
     * @OA\Post(
     *     path="/core-settings/queues/manage",
     *     summary="Создать очередь",
     *     tags={"CoreSettings / Queues"},
     *     security={{"sanctumAuth":{}}},
     *     @OA\RequestBody(@OA\JsonContent(
     *         @OA\Property(property="name", type="string"),
     *         @OA\Property(property="priority", type="integer"),
     *         @OA\Property(property="tries", type="integer"),
     *         @OA\Property(property="timeout", type="integer"),
     *         @OA\Property(property="sleep", type="integer"),
     *         @OA\Property(property="process_via_schedule", type="boolean"),
     *         @OA\Property(property="is_active", type="boolean")
     *     )),
     *     @OA\Response(response=200, description="Очередь создана"),
     *     @OA\Response(response=422, description="Ошибка валидации")
     * )
     */
    public function storeManaged(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'name' => ['required', 'string', 'max:50', 'regex:/^[a-z0-9\-]+$/', 'unique:cms_queues,name'],
            'priority' => 'integer|min:0|max:100',
            'tries' => 'integer|min:1|max:10',
            'timeout' => 'integer|min:5|max:3600',
            'sleep' => 'integer|min:1|max:60',
            'process_via_schedule' => 'boolean',
            'is_active' => 'boolean',
        ]);

        if ($validator->fails()) {
            return $this->error($validator->errors()->first(), 422);
        }

        $queue = Queue::create($validator->validated());
        Queue::clearScheduleCache();

        $this->logAction('queue.create', 'queue', $queue->id, ['name' => $queue->name]);

        return $this->success($queue, 'Очередь создана');
    }

    /**
     * @OA\Put(
     *     path="/core-settings/queues/manage/{id}",
     *     summary="Обновить очередь",
     *     tags={"CoreSettings / Queues"},
     *     security={{"sanctumAuth":{}}},
     *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
     *     @OA\RequestBody(@OA\JsonContent(
     *         @OA\Property(property="priority", type="integer"),
     *         @OA\Property(property="tries", type="integer"),
     *         @OA\Property(property="timeout", type="integer"),
     *         @OA\Property(property="sleep", type="integer"),
     *         @OA\Property(property="process_via_schedule", type="boolean"),
     *         @OA\Property(property="is_active", type="boolean")
     *     )),
     *     @OA\Response(response=200, description="Очередь обновлена"),
     *     @OA\Response(response=404, description="Очередь не найдена")
     * )
     */
    public function updateManaged(Request $request, int $id): JsonResponse
    {
        $queue = Queue::find($id);
        if (!$queue) {
            return $this->error('Очередь не найдена', 404);
        }

        $rules = [
            'priority' => 'integer|min:0|max:100',
            'tries' => 'integer|min:1|max:10',
            'timeout' => 'integer|min:5|max:3600',
            'sleep' => 'integer|min:1|max:60',
            'process_via_schedule' => 'boolean',
            'is_active' => 'boolean',
        ];

        // Only allow renaming if not 'default' queue
        if ($queue->name !== 'default') {
            $rules['name'] = ['string', 'max:50', 'regex:/^[a-z0-9\-]+$/', 'unique:cms_queues,name,' . $queue->id];
        }

        $validator = Validator::make($request->all(), $rules);

        if ($validator->fails()) {
            return $this->error($validator->errors()->first(), 422);
        }

        $queue->update($validator->validated());
        Queue::clearScheduleCache();

        $this->logAction('queue.update', 'queue', $queue->id, ['name' => $queue->name]);

        return $this->success($queue, 'Очередь обновлена');
    }

    /**
     * @OA\Delete(
     *     path="/core-settings/queues/manage/{id}",
     *     summary="Удалить очередь",
     *     tags={"CoreSettings / Queues"},
     *     security={{"sanctumAuth":{}}},
     *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
     *     @OA\Response(response=200, description="Очередь удалена"),
     *     @OA\Response(response=400, description="Невозможно удалить"),
     *     @OA\Response(response=404, description="Очередь не найдена")
     * )
     */
    public function destroyManaged(int $id): JsonResponse
    {
        $queue = Queue::find($id);
        if (!$queue) {
            return $this->error('Очередь не найдена', 404);
        }

        if ($queue->name === 'default') {
            return $this->error('Нельзя удалить очередь default', 400);
        }

        $pendingCount = DB::table('jobs')->where('queue', $queue->name)->count();
        $failedCount = DB::table('failed_jobs')->where('queue', $queue->name)->count();

        if ($pendingCount > 0 || $failedCount > 0) {
            return $this->error("В очереди есть задачи: {$pendingCount} ожидающих, {$failedCount} проваленных", 400);
        }

        if (Queue::count() <= 1) {
            return $this->error('Нельзя удалить последнюю очередь', 400);
        }

        $name = $queue->name;
        $queue->delete();
        Queue::clearScheduleCache();

        $this->logAction('queue.delete', 'queue', $id, ['name' => $name]);

        return $this->success(null, 'Очередь удалена');
    }
}
