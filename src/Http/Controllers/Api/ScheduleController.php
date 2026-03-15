<?php

namespace Templite\Cms\Http\Controllers\Api;

use Cron\CronExpression;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Validator;
use OpenApi\Annotations as OA;
use Templite\Cms\Models\ScheduleHistory;
use Templite\Cms\Models\ScheduledTask;

class ScheduleController extends Controller
{
    private const FREQUENCY_MAP = [
        '* * * * *' => 'Каждую минуту',
        '*/5 * * * *' => 'Каждые 5 минут',
        '*/10 * * * *' => 'Каждые 10 минут',
        '*/15 * * * *' => 'Каждые 15 минут',
        '*/30 * * * *' => 'Каждые 30 минут',
        '0 * * * *' => 'Каждый час',
        '0 */2 * * *' => 'Каждые 2 часа',
        '0 */6 * * *' => 'Каждые 6 часов',
        '0 */12 * * *' => 'Каждые 12 часов',
        '0 0 * * *' => 'Ежедневно',
        '0 0 * * 0' => 'Еженедельно',
        '0 0 1 * *' => 'Ежемесячно',
    ];

    private const COMMAND_BLACKLIST = [
        'migrate:fresh', 'migrate:reset', 'db:wipe', 'db:seed',
        'down', 'up', 'env', 'key:generate', 'storage:link', 'tinker',
    ];

    /**
     * @OA\Get(
     *     path="/core-settings/schedule/tasks",
     *     summary="Список задач расписания",
     *     tags={"CoreSettings / Schedule"},
     *     security={{"sanctumAuth":{}}},
     *     @OA\Response(
     *         response=200,
     *         description="Список задач",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean"),
     *             @OA\Property(property="data", type="object",
     *                 @OA\Property(property="tasks", type="array", @OA\Items(
     *                     @OA\Property(property="id", type="integer"),
     *                     @OA\Property(property="command", type="string"),
     *                     @OA\Property(property="arguments", type="string", nullable=true),
     *                     @OA\Property(property="expression", type="string"),
     *                     @OA\Property(property="description", type="string"),
     *                     @OA\Property(property="frequency", type="string"),
     *                     @OA\Property(property="is_system", type="boolean"),
     *                     @OA\Property(property="is_active", type="boolean"),
     *                     @OA\Property(property="without_overlapping", type="boolean"),
     *                     @OA\Property(property="next_run", type="string", nullable=true),
     *                     @OA\Property(property="last_run", type="object", nullable=true),
     *                     @OA\Property(property="is_running", type="boolean")
     *                 ))
     *             )
     *         )
     *     )
     * )
     */
    public function tasks(): JsonResponse
    {
        $tasks = ScheduledTask::orderBy('is_system', 'desc')->orderBy('id')->get();

        $data = $tasks->map(function ($task) {
            $expression = $task->expression;

            try {
                $cron = new CronExpression($expression);
                $nextRun = $cron->getNextRunDate()->format('c');
            } catch (\Throwable) {
                $nextRun = null;
            }

            $lastRun = ScheduleHistory::forCommand($task->command)
                ->orderByDesc('ran_at')
                ->first();

            return [
                'id' => $task->id,
                'command' => $task->command,
                'arguments' => $task->arguments,
                'description' => $task->description ?? $task->command,
                'frequency' => self::FREQUENCY_MAP[$expression] ?? $expression,
                'expression' => $expression,
                'is_system' => $task->is_system,
                'is_active' => $task->is_active,
                'without_overlapping' => $task->without_overlapping,
                'next_run' => $task->is_active ? $nextRun : null,
                'last_run' => $lastRun ? [
                    'ran_at' => $lastRun->ran_at->toIso8601String(),
                    'status' => $lastRun->status,
                    'duration_ms' => $lastRun->duration_ms,
                ] : null,
                'is_running' => Cache::has("schedule:running:{$task->command}"),
            ];
        });

        return $this->success(['tasks' => $data]);
    }

    /**
     * @OA\Get(
     *     path="/core-settings/schedule/commands",
     *     summary="Список доступных artisan-команд",
     *     tags={"CoreSettings / Schedule"},
     *     security={{"sanctumAuth":{}}},
     *     @OA\Response(response=200, description="Список команд")
     * )
     */
    public function commands(): JsonResponse
    {
        $allowedPrefixes = ['cms:'];
        $allowedExact = ['queue:work'];

        $commands = collect(Artisan::all())
            ->filter(fn ($cmd, $name) =>
                in_array($name, $allowedExact) ||
                collect($allowedPrefixes)->contains(fn ($prefix) => str_starts_with($name, $prefix))
            )
            ->reject(fn ($cmd, $name) => in_array($name, self::COMMAND_BLACKLIST))
            ->map(fn ($cmd, $name) => [
                'name' => $name,
                'description' => $cmd->getDescription(),
            ])
            ->sortBy('name')
            ->values();

        return $this->success($commands);
    }

    /**
     * @OA\Post(
     *     path="/core-settings/schedule/tasks",
     *     summary="Создать задачу расписания",
     *     tags={"CoreSettings / Schedule"},
     *     security={{"sanctumAuth":{}}},
     *     @OA\RequestBody(@OA\JsonContent(
     *         @OA\Property(property="command", type="string"),
     *         @OA\Property(property="arguments", type="string", nullable=true),
     *         @OA\Property(property="expression", type="string"),
     *         @OA\Property(property="description", type="string", nullable=true),
     *         @OA\Property(property="without_overlapping", type="boolean"),
     *         @OA\Property(property="is_active", type="boolean")
     *     )),
     *     @OA\Response(response=200, description="Задача создана"),
     *     @OA\Response(response=422, description="Ошибка валидации")
     * )
     */
    public function storeTask(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'command' => 'required|string|max:255',
            'arguments' => 'nullable|string|max:255',
            'expression' => 'required|string|max:50',
            'description' => 'nullable|string|max:255',
            'without_overlapping' => 'boolean',
            'is_active' => 'boolean',
        ]);

        if ($validator->fails()) {
            return $this->error($validator->errors()->first(), 422);
        }

        $data = $validator->validated();

        if (!array_key_exists($data['command'], Artisan::all())) {
            return $this->error('Команда не зарегистрирована в системе', 422);
        }

        if (in_array($data['command'], self::COMMAND_BLACKLIST)) {
            return $this->error('Эта команда запрещена для добавления в расписание', 422);
        }

        if (!CronExpression::isValidExpression($data['expression'])) {
            return $this->error('Некорректное cron-выражение', 422);
        }

        $data['is_system'] = false;
        $task = ScheduledTask::create($data);
        ScheduledTask::clearScheduleCache();

        $this->logAction('schedule.create', 'scheduled_task', $task->id, [
            'command' => $task->command,
        ]);

        return $this->success($task, 'Задача создана');
    }

    /**
     * @OA\Put(
     *     path="/core-settings/schedule/tasks/{id}",
     *     summary="Обновить задачу расписания",
     *     tags={"CoreSettings / Schedule"},
     *     security={{"sanctumAuth":{}}},
     *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
     *     @OA\RequestBody(@OA\JsonContent(
     *         @OA\Property(property="command", type="string"),
     *         @OA\Property(property="arguments", type="string", nullable=true),
     *         @OA\Property(property="expression", type="string"),
     *         @OA\Property(property="description", type="string", nullable=true),
     *         @OA\Property(property="without_overlapping", type="boolean"),
     *         @OA\Property(property="is_active", type="boolean")
     *     )),
     *     @OA\Response(response=200, description="Задача обновлена"),
     *     @OA\Response(response=404, description="Задача не найдена")
     * )
     */
    public function updateTask(Request $request, int $id): JsonResponse
    {
        $task = ScheduledTask::find($id);
        if (!$task) {
            return $this->error('Задача не найдена', 404);
        }

        $rules = $task->is_system
            ? [
                'expression' => 'string|max:50',
                'description' => 'nullable|string|max:255',
                'is_active' => 'boolean',
                'without_overlapping' => 'boolean',
            ]
            : [
                'command' => 'string|max:255',
                'arguments' => 'nullable|string|max:255',
                'expression' => 'string|max:50',
                'description' => 'nullable|string|max:255',
                'without_overlapping' => 'boolean',
                'is_active' => 'boolean',
            ];

        $validator = Validator::make($request->all(), $rules);
        if ($validator->fails()) {
            return $this->error($validator->errors()->first(), 422);
        }

        $data = $validator->validated();

        if (!$task->is_system && isset($data['command'])) {
            if (!array_key_exists($data['command'], Artisan::all())) {
                return $this->error('Команда не зарегистрирована в системе', 422);
            }
            if (in_array($data['command'], self::COMMAND_BLACKLIST)) {
                return $this->error('Эта команда запрещена', 422);
            }
        }

        if (isset($data['expression']) && !CronExpression::isValidExpression($data['expression'])) {
            return $this->error('Некорректное cron-выражение', 422);
        }

        $task->update($data);
        ScheduledTask::clearScheduleCache();

        $this->logAction('schedule.update', 'scheduled_task', $task->id, [
            'command' => $task->command,
        ]);

        return $this->success($task, 'Задача обновлена');
    }

    /**
     * @OA\Delete(
     *     path="/core-settings/schedule/tasks/{id}",
     *     summary="Удалить задачу расписания",
     *     tags={"CoreSettings / Schedule"},
     *     security={{"sanctumAuth":{}}},
     *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
     *     @OA\Response(response=200, description="Задача удалена"),
     *     @OA\Response(response=400, description="Невозможно удалить системную задачу"),
     *     @OA\Response(response=404, description="Задача не найдена")
     * )
     */
    public function destroyTask(int $id): JsonResponse
    {
        $task = ScheduledTask::find($id);
        if (!$task) {
            return $this->error('Задача не найдена', 404);
        }

        if ($task->is_system) {
            return $this->error('Нельзя удалить системную задачу', 400);
        }

        $command = $task->command;
        $task->delete();
        ScheduledTask::clearScheduleCache();

        $this->logAction('schedule.delete', 'scheduled_task', $id, [
            'command' => $command,
        ]);

        return $this->success(null, 'Задача удалена');
    }

    /**
     * @OA\Get(
     *     path="/core-settings/schedule/history",
     *     summary="История запусков расписания",
     *     tags={"CoreSettings / Schedule"},
     *     security={{"sanctumAuth":{}}},
     *     @OA\Parameter(name="command", in="query", required=false, @OA\Schema(type="string")),
     *     @OA\Parameter(name="page", in="query", required=false, @OA\Schema(type="integer")),
     *     @OA\Parameter(name="per_page", in="query", required=false, @OA\Schema(type="integer")),
     *     @OA\Response(response=200, description="История запусков")
     * )
     */
    public function history(Request $request): JsonResponse
    {
        $perPage = min((int) $request->input('per_page', 20), 100);

        $query = ScheduleHistory::recent()->orderByDesc('ran_at');

        if ($command = $request->input('command')) {
            $query->forCommand($command);
        }

        $paginated = $query->paginate($perPage);

        return $this->success([
            'data' => $paginated->items(),
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
     *     path="/core-settings/schedule/tasks/{command}/run",
     *     summary="Запустить задачу вручную",
     *     tags={"CoreSettings / Schedule"},
     *     security={{"sanctumAuth":{}}},
     *     @OA\Parameter(name="command", in="path", required=true, @OA\Schema(type="string")),
     *     @OA\Response(response=200, description="Результат запуска")
     * )
     */
    public function run(string $command): JsonResponse
    {
        $event = $this->findScheduleEvent($command);
        $isDbOnlyTask = false;

        if (!$event) {
            $dbTask = ScheduledTask::where('command', $command)->first();
            if (!$dbTask) {
                return $this->error('Команда не найдена', 404);
            }
            if (in_array($command, self::COMMAND_BLACKLIST)) {
                return $this->error('Эта команда запрещена для запуска', 403);
            }
            $isDbOnlyTask = true;
        }

        $lock = Cache::lock("schedule:lock:{$command}", 120);
        if (!$lock->get()) {
            return $this->error('Задача уже выполняется', 409);
        }

        set_time_limit(120);

        $startTime = microtime(true);
        Cache::put("schedule:running:{$command}", true, 300);

        try {
            if ($isDbOnlyTask) {
                $exitCode = Artisan::call($command);
                $output = Artisan::output();
            } elseif (!isset($event->command)) {
                $event->run(app());
                $exitCode = 0;
                $output = '';
            } else {
                $exitCode = Artisan::call($command);
                $output = Artisan::output();
            }

            $durationMs = (int) ((microtime(true) - $startTime) * 1000);
            $status = $exitCode === 0 ? 'success' : 'fail';

            ScheduleHistory::create([
                'command' => $command,
                'status' => $status,
                'output' => trim($output) ?: null,
                'duration_ms' => $durationMs,
                'error' => $status === 'fail' ? "Exit code: {$exitCode}" : null,
                'ran_at' => now(),
            ]);

            Cache::forget("schedule:running:{$command}");
            $lock->release();

            $this->logAction('schedule.run', 'schedule_task', null, [
                'command' => $command,
                'status' => $status,
                'duration_ms' => $durationMs,
            ]);

            return $this->success([
                'status' => $status,
                'duration_ms' => $durationMs,
                'output' => trim($output) ?: null,
            ]);
        } catch (\Throwable $e) {
            $durationMs = (int) ((microtime(true) - $startTime) * 1000);

            ScheduleHistory::create([
                'command' => $command,
                'status' => 'fail',
                'duration_ms' => $durationMs,
                'error' => $e->getMessage(),
                'ran_at' => now(),
            ]);

            Cache::forget("schedule:running:{$command}");
            $lock->release();

            return $this->error($e->getMessage(), 500);
        }
    }

    private function extractCommandName($event): ?string
    {
        if (isset($event->command)) {
            if (preg_match("/artisan['\"]?\s+([a-z0-9:\-]+)/i", $event->command, $matches)) {
                return $matches[1];
            }
        }

        if (isset($event->description) && $event->description) {
            return $event->description;
        }

        return null;
    }

    private function findScheduleEvent(string $command)
    {
        $schedule = app(Schedule::class);

        foreach ($schedule->events() as $event) {
            if ($this->extractCommandName($event) === $command) {
                return $event;
            }
        }

        return null;
    }
}
