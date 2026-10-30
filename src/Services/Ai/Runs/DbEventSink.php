<?php

namespace Templite\Cms\Services\Ai\Runs;

use Templite\Cms\Models\AiRunEvent;

/**
 * Журнал хода в БД. Дельты текста и рассуждений копятся и пишутся одной
 * записью раз в ~300 мс: строка на каждый токен раздула бы таблицу в разы.
 */
class DbEventSink implements EventSink
{
    private const FLUSH_INTERVAL = 0.3;

    private const TEXT_EVENTS = ['delta', 'thinking'];

    private ?string $pendingType = null;

    private string $pendingText = '';

    private float $pendingSince = 0.0;

    public function __construct(private readonly int $runId)
    {
    }

    public function emit(string $type, array $data = []): void
    {
        if (! in_array($type, self::TEXT_EVENTS, true)) {
            $this->flush();
            $this->write($type, $data);

            return;
        }

        if ($this->pendingType !== null && $this->pendingType !== $type) {
            $this->flush();
        }

        if ($this->pendingType === null) {
            $this->pendingType = $type;
            $this->pendingSince = microtime(true);
        }

        $this->pendingText .= (string) ($data['text'] ?? '');

        if (microtime(true) - $this->pendingSince >= self::FLUSH_INTERVAL) {
            $this->flush();
        }
    }

    public function flush(): void
    {
        if ($this->pendingType === null) {
            return;
        }

        $type = $this->pendingType;
        $text = $this->pendingText;
        $this->pendingType = null;
        $this->pendingText = '';

        if ($text !== '') {
            $this->write($type, ['text' => $text]);
        }
    }

    /** @param array<string,mixed> $data */
    private function write(string $type, array $data): void
    {
        AiRunEvent::create([
            'run_id' => $this->runId,
            'type' => $type,
            'payload' => $data,
            'created_at' => now(),
        ]);
    }
}
