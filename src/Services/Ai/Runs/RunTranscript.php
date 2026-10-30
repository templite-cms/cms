<?php

namespace Templite\Cms\Services\Ai\Runs;

use Templite\Cms\Models\AiRunEvent;

/**
 * Свёртка журнала хода в сообщение ассистента: тот же формат content/meta,
 * что раньше собирал контроллер из живого потока.
 */
final class RunTranscript
{
    public string $answer = '';

    /** @var array<int,array{thinking:string,text:string,tools:array<int,string>}> */
    public array $steps = [];

    /** @var array{thinking:string,text:string,tools:array<int,string>} незакрытый шаг */
    public array $step = ['thinking' => '', 'text' => '', 'tools' => []];

    /** @var array<int,string> */
    public array $tools = [];

    /** @var array<int,array<string,mixed>> */
    public array $links = [];

    /** @var array<int,array<string,mixed>> */
    public array $images = [];

    /** @var array<int,string> */
    public array $toolLog = [];

    public bool $truncated = false;

    public bool $compacted = false;

    /** @var array{code:string,message:string}|null */
    public ?array $error = null;

    public static function fromRun(int $runId): self
    {
        $transcript = new self();

        AiRunEvent::where('run_id', $runId)
            ->orderBy('id')
            ->lazyById(500)
            ->each(fn (AiRunEvent $e) => $transcript->apply($e->type, $e->payload ?? []));

        return $transcript;
    }

    /** @param array<string,mixed> $data */
    public function apply(string $type, array $data): void
    {
        switch ($type) {
            case 'delta':
                $this->step['text'] .= (string) ($data['text'] ?? '');
                break;
            case 'thinking':
                $this->step['thinking'] .= (string) ($data['text'] ?? '');
                break;
            case 'retrying':
                // Шаг запрошен заново — частичный текст упавшей попытки не нужен.
                if (! empty($data['reset_step'])) {
                    $this->step['text'] = '';
                    $this->step['thinking'] = '';
                }
                break;
            case 'tool_call':
                $name = (string) ($data['name'] ?? '');
                $this->tools[] = $name;
                $this->step['tools'][] = $name;
                $this->toolLog[] = ToolLog::call($name, is_array($data['args'] ?? null) ? $data['args'] : []);
                break;
            case 'tool_result':
                $last = array_key_last($this->toolLog);
                if ($last !== null) {
                    $this->toolLog[$last] .= ' → '.($data['summary'] ?? 'ок');
                }
                break;
            case 'step_end':
                // Шаг без инструментов — финальный: его текст уходит в ответ,
                // рассуждение остаётся шагом, чтобы не потерять ход мысли.
                if ($data['has_tools'] ?? true) {
                    $this->steps[] = $this->step;
                } else {
                    $this->answer = $this->stripServiceLog($this->step['text']);

                    if (trim($this->step['thinking']) !== '') {
                        $this->steps[] = ['thinking' => $this->step['thinking'], 'text' => '', 'tools' => []];
                    }
                }
                $this->step = ['thinking' => '', 'text' => '', 'tools' => []];
                break;
            case 'navigate':
                if (! empty($data['url'])) {
                    $this->links[] = $data;
                }
                break;
            case 'image_review':
                $this->images[] = $data;
                break;
            case 'truncated':
                $this->truncated = true;
                break;
            case 'compacted':
                $this->compacted = true;
                break;
            case 'error':
                $this->error = [
                    'code' => (string) ($data['code'] ?? 'error'),
                    'message' => (string) ($data['message'] ?? ''),
                ];
                break;
        }
    }

    /**
     * Служебные строки журнала в начале ответа. В истории ответ подаётся модели
     * как «[Действия: …]» + текст, и она порой повторяет этот формат сама —
     * пользователю такие строки не нужны: действия он видит отдельно.
     */
    private function stripServiceLog(string $text): string
    {
        return preg_replace('/^(\s*\[(Действи[ея]|Ход прерван)[^\]]*\]\s*)+/u', '', $text) ?? $text;
    }

    /** Ход оборвался посреди шага — незакрытый шаг тоже сохраняем: по нему видно, на чём встал. */
    public function closeOpenStep(): void
    {
        if (trim($this->step['thinking']) !== '' || trim($this->step['text']) !== '' || $this->step['tools'] !== []) {
            $this->steps[] = $this->step;
        }

        $this->step = ['thinking' => '', 'text' => '', 'tools' => []];
    }

    /** @return array<string,mixed> meta сообщения ассистента */
    public function meta(): array
    {
        return array_filter([
            'tools' => $this->tools,
            'links' => $this->links,
            'images' => $this->images,
            'steps' => $this->steps,
            'tool_log' => $this->toolLog,
            'truncated' => $this->truncated ?: null,
            'compacted' => $this->compacted ?: null,
            'error' => $this->error,
        ], fn ($value) => $value !== null);
    }
}
