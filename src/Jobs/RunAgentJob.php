<?php

namespace Templite\Cms\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Templite\Cms\Models\AiRun;
use Templite\Cms\Services\Ai\Runs\AgentRunner;

/**
 * Ход ИИ-агента в воркере очереди.
 *
 * retry_after очереди (90 с по умолчанию) короче хода: при нескольких воркерах
 * задание могут выдать повторно — AgentRunner забирает ход атомарно и второй
 * раз его не выполнит. Чтобы такая повторная выдача не упала в failed_jobs
 * по лимиту попыток, tries=0: копия просто тихо завершается на claim.
 * Исключение внутри хода (maxExceptions=1) не повторяется.
 */
class RunAgentJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    /**
     * Без лимита выдач: повторную выдачу по retry_after отсекает claim в
     * AgentRunner, а лимит в 1 отправил бы её в failed_jobs.
     */
    public int $tries = 0;

    /** Упавший ход не повторяем: инструменты уже могли что-то изменить. */
    public int $maxExceptions = 1;

    /** Ход длится минутами — лимит воркера по умолчанию (60 с) убил бы его. */
    public int $timeout = 3600;

    public function __construct(public readonly int $runId)
    {
    }

    public function handle(AgentRunner $runner): void
    {
        $run = AiRun::find($this->runId);

        if ($run) {
            $runner->run($run);
        }
    }
}
