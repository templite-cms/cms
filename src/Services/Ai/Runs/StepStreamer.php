<?php

namespace Templite\Cms\Services\Ai\Runs;

use Templite\Cms\Exceptions\AiRequestException;
use Templite\Cms\Exceptions\RunCancelledException;
use Templite\Cms\Services\Ai\OpenRouterClient;

class StepStreamer
{
    /*
     * Состояние текущего шага. Класс берётся из контейнера и может жить
     * дольше одного шага (воркер очереди), поэтому stream() сбрасывает его
     * в начале.
     */
    private string $text = '';

    /** @var array<string,array<string,mixed>> */
    private array $calls = [];

    /** @var array<int|string,string> index из потока → ключ записи в $calls */
    private array $callKeys = [];

    private ?string $finishReason = null;

    /** @var array<string,mixed>|null */
    private ?array $usage = null;

    /**
     * Один шаг модели: читает поток, отдаёт дельты, копит текст и tool_calls.
     *
     * @param  array<string,mixed>  $payload
     * @param  callable(string,array):void  $emit
     * @param  callable():void  $tick  heartbeat и проверка отмены, на каждом чанке
     *
     * @throws AiRequestException
     * @throws RunCancelledException
     */
    public function stream(OpenRouterClient $client, array $payload, callable $emit, callable $tick): StepResult
    {
        $this->text = '';
        $this->calls = [];
        $this->callKeys = [];
        $this->finishReason = null;
        $this->usage = null;
        $buffer = '';

        try {
            foreach ($client->chatStream($payload) as $chunk) {
                // chatStream отдаёт поток построчно. Служебные комментарии
                // OpenRouter («: OPENROUTER PROCESSING») — тоже строки: пока
                // модель молчит, ход подаёт признаки жизни.
                $tick();
                $buffer .= $chunk;

                while (($pos = strpos($buffer, "\n")) !== false) {
                    $this->consumeLine(substr($buffer, 0, $pos), $emit);
                    $buffer = substr($buffer, $pos + 1);
                }
            }

            // Последняя строка может прийти без перевода строки — в ней
            // нередко как раз finish_reason.
            if ($buffer !== '') {
                $this->consumeLine($buffer, $emit);
            }
        } catch (AiRequestException|RunCancelledException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw AiRequestException::classify($e) ?? $e;
        }

        if ($this->finishReason === 'error') {
            throw new AiRequestException('stream_error', 'Провайдер завершил ответ ошибкой.');
        }

        // Без finish_reason поток оборвался посреди ответа. Даже при накопленном
        // тексте это не успех: ошибка повторяемая, частичный текст сбросит reset_step.
        if ($this->finishReason === null) {
            throw new AiRequestException('stream_error', 'Поток ответа оборвался до завершения.');
        }

        return new StepResult($this->text, array_values($this->calls), $this->finishReason, $this->usage);
    }

    /**
     * Разобрать одну строку SSE.
     *
     * @param  callable(string,array):void  $emit
     *
     * @throws AiRequestException
     */
    private function consumeLine(string $line, callable $emit): void
    {
        $line = trim($line);

        if (! str_starts_with($line, 'data:')) {
            return;
        }

        $json = trim(substr($line, 5));

        if ($json === '' || $json === '[DONE]') {
            return;
        }

        $decoded = json_decode($json, true);

        if (! is_array($decoded)) {
            return;
        }

        // Провайдер может упасть посреди ответа: ошибка приходит чанком,
        // а не HTTP-статусом. Без этой ветки ход выглядел бы пустым,
        // но успешным.
        if (isset($decoded['error'])) {
            $err = $decoded['error'];
            $status = is_numeric($err['code'] ?? null) ? (int) $err['code'] : 500;

            throw AiRequestException::fromStatus($status, AiRequestException::providerMessage(is_array($err) ? $err : []));
        }

        if (isset($decoded['usage']) && is_array($decoded['usage'])) {
            $this->usage = $decoded['usage'];
        }

        $choice = $decoded['choices'][0] ?? [];

        if (! empty($choice['finish_reason'])) {
            $this->finishReason = (string) $choice['finish_reason'];
        }

        $delta = $choice['delta'] ?? [];

        if (isset($delta['content']) && $delta['content'] !== '') {
            $this->text .= $delta['content'];
            $emit('delta', ['text' => $delta['content']]);
        }

        // Размышления идут отдельным полем; ключ зависит от провайдера.
        $reasoning = $delta['reasoning'] ?? $delta['reasoning_content'] ?? null;

        if (is_string($reasoning) && $reasoning !== '') {
            $emit('thinking', ['text' => $reasoning]);
        }

        foreach ($delta['tool_calls'] ?? [] as $tc) {
            $this->appendToolCall($tc);
        }
    }

    /**
     * Склеить фрагмент tool_call. Обычно фрагменты одного вызова идут с одним
     * index, но некоторые провайдеры шлют index 0 на каждый вызов — тогда
     * новый вызов узнаём по другому id.
     *
     * @param  array<string,mixed>  $tc
     */
    private function appendToolCall(array $tc): void
    {
        $index = $tc['index'] ?? 0;
        $id = isset($tc['id']) && $tc['id'] !== '' ? (string) $tc['id'] : null;
        $key = $this->callKeys[$index] ?? null;

        if ($key !== null && $id !== null && ! empty($this->calls[$key]['id']) && $this->calls[$key]['id'] !== $id) {
            $key = null;
        }

        if ($key === null) {
            $key = isset($this->calls[(string) $index]) ? $index.'#'.count($this->calls) : (string) $index;
            $this->callKeys[$index] = $key;
            $this->calls[$key] = ['id' => $id, 'type' => 'function', 'function' => ['name' => '', 'arguments' => '']];
        }

        if ($id !== null) {
            $this->calls[$key]['id'] = $id;
        }

        $this->calls[$key]['function']['name'] .= $tc['function']['name'] ?? '';
        $this->calls[$key]['function']['arguments'] .= $tc['function']['arguments'] ?? '';
    }
}
