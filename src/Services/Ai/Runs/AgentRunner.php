<?php

namespace Templite\Cms\Services\Ai\Runs;

use Illuminate\Support\Facades\Auth;
use Templite\Cms\Exceptions\AiRequestException;
use Templite\Cms\Exceptions\RunCancelledException;
use Templite\Cms\Exceptions\UnsupportedAiProviderException;
use Templite\Cms\Models\AiChatSession;
use Templite\Cms\Models\AiKey;
use Templite\Cms\Models\AiRun;
use Templite\Cms\Models\Manager;
use Templite\Cms\Services\ActionChannel;
use Templite\Cms\Services\Ai\AiSettingsService;
use Templite\Cms\Services\Ai\Context\ContextBuilder;
use Templite\Cms\Services\Ai\Context\ContextCompactor;
use Templite\Cms\Services\Ai\Context\SystemPrompt;
use Templite\Cms\Services\Ai\Context\TokenEstimator;
use Templite\Cms\Services\Ai\EditorContext;
use Templite\Cms\Services\Ai\OpenRouterClient;
use Templite\Cms\Services\Ai\Tools\GenerateImageTool;
use Templite\Cms\Services\Ai\Tools\ToolRegistry;

/**
 * Исполнитель хода агента. Работает вне HTTP-ответа (очередь или после
 * отправки ответа), поэтому всё, что видит пользователь, уходит в журнал
 * событий, а не в поток.
 */
class AgentRunner
{
    private const MAX_RETRIES = 3;

    private const RETRY_DELAYS = [2, 5, 10];

    private const MAX_RETRY_DELAY = 60;

    /** Как часто обновлять heartbeat и проверять отмену, секунд. */
    private const TICK_INTERVAL = 3.0;

    /** Сколько последних результатов инструментов не трогает сжатие внутри хода. */
    private const KEEP_TOOL_RESULTS = 2;

    private const TRIMMED_RESULT = '[результат опущен ради места — вызови инструмент повторно, если нужен]';

    private float $lastTick = 0.0;

    /**
     * Токены и стоимость, накопленные за ход по всем шагам модели.
     *
     * @var array<string,int|float>
     */
    private array $usage = [];

    public function __construct(
        private readonly ToolRegistry $tools,
        private readonly AiSettingsService $settings,
        private readonly SystemPrompt $systemPrompt,
        private readonly ContextBuilder $context,
        private readonly ContextCompactor $compactor,
        private readonly TokenEstimator $tokens,
        private readonly StepStreamer $streamer,
        private readonly EditorOpStore $editorOps,
        private readonly RunFinalizer $finalizer,
        private readonly AiRunDispatcher $dispatcher,
    ) {
    }

    public function run(AiRun $run): void
    {
        // Забираем ход атомарно: повторная выдача задания очередью (retry_after)
        // не должна запустить тот же ход второй раз.
        $claimed = AiRun::whereKey($run->id)
            ->where('status', AiRun::RUNNING)
            ->whereNull('started_at')
            ->update(['started_at' => now(), 'heartbeat_at' => now()]);

        if (! $claimed) {
            return;
        }

        $run->refresh();
        $sink = new DbEventSink($run->id);
        $this->lastTick = microtime(true);
        // Раннер — сервис из контейнера и переживает ход: счёт каждого хода с нуля.
        $this->usage = [];
        $previousGuard = Auth::getDefaultDriver();
        // Берём из контейнера на каждый ход, а не в конструкторе: раннер живёт
        // дольше задания, а ActionChannel — scoped и между заданиями пересоздаётся
        $channel = app(ActionChannel::class);
        $channel->enter(ActionChannel::ASSISTANT, $run->id);

        try {
            $note = $this->execute($run, $sink);
            $sink->flush();
            $this->close($run, AiRun::COMPLETED, $note);
        } catch (RunCancelledException) {
            // Ход мог закрыть сторож — после его done в журнал не пишем.
            if (AiRun::whereKey($run->id)->whereIn('status', AiRun::ACTIVE)->exists()) {
                $sink->flush();
            }

            $this->close($run, AiRun::CANCELLED, ['code' => 'cancelled', 'message' => 'Остановлено.']);
        } catch (AiRequestException $e) {
            $sink->flush();
            $this->close($run, AiRun::FAILED, ['code' => $e->errorCode, 'message' => $e->userMessage()]);
        } catch (UnsupportedAiProviderException $e) {
            $sink->flush();
            $this->close($run, AiRun::FAILED, ['code' => 'provider_unsupported', 'message' => $e->getMessage()]);
        } catch (\Throwable $e) {
            report($e);

            try {
                $sink->flush();
            } catch (\Throwable) {
                // Журнал недоступен — закрываем ход тем, что успело записаться.
            }

            // Текст исключения пользователю не показываем: у QueryException в нём
            // SQL с данными. Подробности — в report() выше.
            $this->close($run, AiRun::FAILED, ['code' => 'internal_error', 'message' => 'Внутренняя ошибка ассистента — подробности в журнале сервера.']);
        } finally {
            $channel->leave();
            // Ключ не должен пережить ход: в воркере им воспользовался бы следующий.
            GenerateImageTool::clear();
            // Вход под автором хода тоже: воркер живёт долго, и следующее
            // задание не должно выполняться от имени этого менеджера.
            Auth::guard('manager')->forgetUser();
            Auth::shouldUse($previousGuard);
        }

        // Очередь сообщений диалога: следующий ход стартует сам. Сбой запуска
        // не должен ронять задание текущего хода — он уже закрыт.
        try {
            $this->dispatcher->startNext($run->session_id);
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /**
     * Закрыть ход. Сбой закрытия не должен ронять воркер: ход дочистит сторож,
     * а очередь диалога всё равно надо двигать дальше.
     *
     * @param  array{code:string,message:string}|null  $error
     */
    private function close(AiRun $run, string $status, ?array $error = null): void
    {
        // Расход пишем до закрытия: закрытый ход клиент уже может перечитать.
        // Сбой записи — не повод оставлять ход незакрытым.
        if ($this->usage !== []) {
            try {
                $run->forceFill(['usage' => $this->usage])->saveQuietly();
            } catch (\Throwable $e) {
                report($e);
            }
        }

        try {
            $this->finalizer->finalize($run, $status, $error);
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /** @return array{code:string,message:string}|null пометка к успешно закрытому ходу */
    private function execute(AiRun $run, EventSink $sink): ?array
    {
        $session = AiChatSession::findOrFail($run->session_id);
        $key = $session->key_id ? AiKey::find($session->key_id) : null;

        if (! $key) {
            throw new AiRequestException('no_key', 'Ключ диалога не найден.');
        }

        $this->actAs($run);

        $client = OpenRouterClient::forKey($key);
        // Модель диалога главнее модели ключа.
        $model = $session->model ?: $key->chatModel();

        GenerateImageTool::useKey($key, $session->image_model);
        // Лимит генераций считается на один ход, а не на весь диалог.
        GenerateImageTool::resetCounter();

        $ctx = EditorContext::fromRequest($run->context);
        $schemas = $this->tools->schemas();
        $window = OpenRouterClient::catalog()->contextLength($model) ?? RunSettings::defaultContextLength();

        $this->compactBeforeTurn($run, $session, $client, $model, $ctx, $schemas, $window, $sink);

        $history = $this->context->history($session, $run->message_id);
        $turn = [];
        $overflowHandled = false;
        $maxSteps = $key->stepLimit();

        for ($step = 0; $step < $maxSteps; $step++) {
            $this->tick($run, $sink, force: true);

            $system = $this->systemPrompt->build($this->settings->systemPrompt(), $ctx, $session->summary);
            $turn = $this->fitTurn($session, $system, $history, $turn, $schemas, $window);

            $payload = [
                'model' => $model,
                'messages' => array_merge([['role' => 'system', 'content' => $system]], $history, $turn),
                'tools' => $schemas,
                'tool_choice' => 'auto',
                'max_tokens' => RunSettings::maxTokens(),
                'usage' => ['include' => true],
            ];

            try {
                $result = $this->streamWithRetries($client, $payload, $run, $sink);
            } catch (AiRequestException $e) {
                if ($e->errorCode !== 'context_overflow' || $overflowHandled) {
                    throw $e;
                }

                // Оценка размера промахнулась — жмём жёстче и повторяем шаг один раз.
                $overflowHandled = true;
                $sink->emit('retrying', ['attempt' => 1, 'delay' => 0, 'reset_step' => true, 'message' => 'Контекст переполнен — сжимаю историю.']);

                // Как в compactBeforeTurn: сжатие долгое — признак жизни до и после.
                $this->tick($run, $sink, force: true);

                if ($this->tryCompact($session, $client, $model, $run->message_id, 2)) {
                    $history = $this->context->history($session, $run->message_id);
                    $sink->emit('compacted', []);
                }

                $this->tick($run, $sink, force: true);

                $turn = $this->dropToolResults($turn, 1);
                $step--;

                continue;
            }

            $this->tokens->calibrate($session, $payload, $result->usage);
            $this->addUsage($result->usage);

            if ($result->toolCalls === []) {
                if ($result->finishReason === 'length') {
                    $sink->emit('truncated', []);
                }

                // Шаг без инструментов — последний: его текст и есть ответ.
                $sink->emit('step_end', ['has_tools' => false]);

                return null;
            }

            $turn[] = ['role' => 'assistant', 'content' => $result->text, 'tool_calls' => $result->toolCalls];

            foreach ($result->toolCalls as $call) {
                $turn[] = $this->callTool($call, $result->finishReason, $ctx, $run, $sink);
                $this->tick($run, $sink);
            }

            // Шаг закрыт вместе со своими инструментами.
            $sink->emit('step_end', ['has_tools' => true]);
        }

        return ['code' => 'max_steps', 'message' => "Достигнут лимит шагов ({$maxSteps}). Нажмите «Продолжить», чтобы агент довёл работу."];
    }

    /**
     * Инструменты проверяют права через auth() и зовут контроллеры от имени
     * пользователя. В очереди запроса нет — входим под автором хода.
     */
    /**
     * Прибавить расход шага к итогу хода. Считаем только числовые счётчики:
     * вложенные детали (кэш, reasoning) у провайдеров разные и в сумме не нужны.
     *
     * @param  array<string,mixed>|null  $usage
     */
    private function addUsage(?array $usage): void
    {
        foreach (['prompt_tokens', 'completion_tokens', 'total_tokens', 'cost'] as $field) {
            if (isset($usage[$field]) && is_numeric($usage[$field])) {
                $this->usage[$field] = ($this->usage[$field] ?? 0) + $usage[$field];
            }
        }
    }

    private function actAs(AiRun $run): void
    {
        $manager = Manager::find($run->manager_id);

        if (! $manager) {
            throw new \RuntimeException('Автор хода удалён.');
        }

        Auth::shouldUse('manager');
        Auth::guard('manager')->setUser($manager);
    }

    /** @param array<int,array<string,mixed>> $schemas */
    private function compactBeforeTurn(AiRun $run, AiChatSession $session, OpenRouterClient $client, string $model, ?EditorContext $ctx, array $schemas, int $window, EventSink $sink): void
    {
        $system = $this->systemPrompt->build($this->settings->systemPrompt(), $ctx, $session->summary);
        $history = $this->context->history($session, $run->message_id);

        if (! $this->tokens->exceeds($session, [$system, $history, $schemas], $window)) {
            return;
        }

        // Сжатие — отдельный запрос к модели, до двух минут: признак жизни до
        // и после, чтобы сторож не счёл ход зависшим, и проверка отмены.
        $this->tick($run, $sink, force: true);

        if ($this->tryCompact($session, $client, $model, $run->message_id, ContextCompactor::KEEP_RECENT)) {
            $sink->emit('compacted', []);
        }

        $this->tick($run, $sink, force: true);
    }

    /** Сжатие — оптимизация: если оно сорвалось, ход всё равно пробует уложиться. */
    private function tryCompact(AiChatSession $session, OpenRouterClient $client, string $model, int $uptoMessageId, int $keepRecent): bool
    {
        try {
            $done = $this->compactor->compact($session, $client, $model, $uptoMessageId, $keepRecent);
        } catch (\Throwable $e) {
            report($e);

            return false;
        }

        if ($done) {
            $session->refresh();
        }

        return $done;
    }

    /**
     * Длинный ход с десятком инструментов переполняет окно сам: старые
     * результаты заменяем заглушкой, последние оставляем.
     *
     * @param  array<int,array<string,mixed>>  $history
     * @param  array<int,array<string,mixed>>  $turn
     * @param  array<int,array<string,mixed>>  $schemas
     * @return array<int,array<string,mixed>>
     */
    private function fitTurn(AiChatSession $session, string $system, array $history, array $turn, array $schemas, int $window): array
    {
        if (! $this->tokens->exceeds($session, [$system, $history, $turn, $schemas], $window)) {
            return $turn;
        }

        return $this->dropToolResults($turn, self::KEEP_TOOL_RESULTS);
    }

    /**
     * @param  array<int,array<string,mixed>>  $turn
     * @return array<int,array<string,mixed>>
     */
    private function dropToolResults(array $turn, int $keep): array
    {
        $toolIndexes = array_keys(array_filter($turn, fn (array $m) => $m['role'] === 'tool'));

        foreach (array_slice($toolIndexes, 0, max(0, count($toolIndexes) - $keep)) as $i) {
            $turn[$i]['content'] = self::TRIMMED_RESULT;
        }

        return $turn;
    }

    /** @param array<string,mixed> $payload */
    private function streamWithRetries(OpenRouterClient $client, array $payload, AiRun $run, EventSink $sink): StepResult
    {
        for ($attempt = 0; ; $attempt++) {
            try {
                return $this->streamer->stream(
                    $client,
                    $payload,
                    fn (string $type, array $data) => $sink->emit($type, $data),
                    fn () => $this->tick($run, $sink),
                );
            } catch (AiRequestException $e) {
                if (! $e->retryable() || $attempt >= self::MAX_RETRIES) {
                    throw $e;
                }

                $delay = min($e->retryAfter ?? self::RETRY_DELAYS[$attempt], self::MAX_RETRY_DELAY);

                // Шаг повторяется целиком: инструменты вызываются только после
                // потока, так что повтор ничего не задвоит.
                $sink->emit('retrying', [
                    'attempt' => $attempt + 1,
                    'delay' => $delay,
                    'reset_step' => true,
                    'message' => $e->userMessage(),
                ]);

                $this->sleep($delay, $run, $sink);
            }
        }
    }

    private function sleep(int $seconds, AiRun $run, EventSink $sink): void
    {
        $until = microtime(true) + $seconds;

        while (microtime(true) < $until) {
            $this->tick($run, $sink);
            usleep(500_000);
        }
    }

    /**
     * Признак жизни для сторожа и проверка отмены. Дёргается на каждом чанке
     * потока, поэтому без force срабатывает не чаще TICK_INTERVAL.
     *
     * @throws RunCancelledException
     */
    private function tick(AiRun $run, EventSink $sink, bool $force = false): void
    {
        $now = microtime(true);

        if (! $force && $now - $this->lastTick < self::TICK_INTERVAL) {
            return;
        }

        $this->lastTick = $now;

        $state = AiRun::whereKey($run->id)->first(['id', 'status', 'cancel_requested_at']);

        // Диалог удалили вместе с ходом или ход закрыли снаружи — работать не для кого.
        // Проверка до записи: в закрытый ход журнал больше не пишем.
        if (! $state || ! $state->isActive() || $state->cancel_requested_at) {
            throw new RunCancelledException();
        }

        $sink->flush();
        AiRun::whereKey($run->id)->update(['heartbeat_at' => now()]);
    }

    /**
     * @param  array<string,mixed>  $call
     * @return array<string,mixed> сообщение role=tool для модели
     */
    private function callTool(array $call, ?string $finishReason, ?EditorContext $ctx, AiRun $run, EventSink $sink): array
    {
        $name = (string) ($call['function']['name'] ?? '');
        $args = json_decode(($call['function']['arguments'] ?? '') ?: '{}', true);

        $sink->emit('tool_call', ['name' => $name, 'args' => ToolLog::compactArgs(is_array($args) ? $args : [])]);

        if (! is_array($args)) {
            // Битые аргументы нельзя подменять пустыми: инструмент выполнился
            // бы с дефолтами и сделал не то, что просила модель.
            $result = $finishReason === 'length'
                ? ['error' => 'arguments_truncated', 'message' => 'Аргументы вызова обрезаны лимитом длины ответа — разбей правку на несколько вызовов поменьше.']
                : ['error' => 'invalid_arguments', 'message' => 'Аргументы вызова — невалидный JSON. Повтори вызов с корректными аргументами.'];
        } else {
            $result = $this->tools->execute($name, $args, $ctx);
        }

        $sink->emit('tool_result', ['name' => $name, 'summary' => $this->summarize($result)]);

        // Только массивы: мусор от инструмента редактор всё равно не применит.
        if (is_array($result['editor_op'] ?? null)) {
            $sink->emit('editor_op', $this->editorOp($result, $ctx, $run));
        }

        if (is_array($result['image_review'] ?? null)) {
            $sink->emit('image_review', $result['image_review']);
        }

        // Инструмент отдаёт либо карточку страницы (page_id, title, url), либо,
        // как раньше, флаг + отдельный url.
        $navigate = $result['navigate'] ?? null;

        if (is_array($navigate) && ! empty($navigate['url'])) {
            $sink->emit('navigate', $navigate);
        } elseif ($navigate && isset($result['url'])) {
            $sink->emit('navigate', ['url' => $result['url']]);
        }

        return [
            'role' => 'tool',
            'tool_call_id' => $call['id'] ?? $name,
            'content' => $this->tools->encodeResult($name, $result),
        ];
    }

    /**
     * Правку, которую редактор должен применить сам, запоминаем: вкладку могли
     * закрыть или перезагрузить — тогда её применят при следующем открытии.
     *
     * @param  array<string,mixed>  $result
     * @return array<string,mixed>
     */
    private function editorOp(array $result, ?EditorContext $ctx, AiRun $run): array
    {
        $op = $result['editor_op'];

        if (($result['status'] ?? null) !== 'proposed_to_editor') {
            return $op;
        }

        $record = $this->editorOps->record($run, $ctx, $op);

        return $record
            ? $op + ['op_id' => $record->id, 'target' => ['type' => $record->target_type, 'id' => $record->target_id]]
            : $op;
    }

    /** @param array<string,mixed> $result */
    private function summarize(array $result): string
    {
        if (isset($result['error'])) {
            $message = is_string($result['message'] ?? null) ? ': '.mb_substr($result['message'], 0, 120) : '';

            return 'ошибка '.$result['error'].$message;
        }

        if (($result['status'] ?? null) === 'proposed_to_editor') {
            return 'предложено в редактор';
        }

        return 'ок';
    }
}
