<?php

namespace Templite\Cms\Exceptions;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;

/**
 * Сбой запроса к провайдеру модели, разобранный по смыслу: от кода зависит,
 * повторять ли запрос и что показать пользователю.
 */
class AiRequestException extends \RuntimeException
{
    /** Коды, при которых повтор того же запроса имеет смысл. */
    private const RETRYABLE = ['rate_limited', 'provider_error', 'network_error', 'stream_error'];

    private const OVERFLOW_MARKERS = [
        'context length',
        'context_length',
        'maximum context',
        'context window',
        'too many tokens',
        'prompt is too long',
    ];

    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly ?int $status = null,
        public readonly ?int $retryAfter = null,
    ) {
        parent::__construct($message);
    }

    public function retryable(): bool
    {
        return in_array($this->errorCode, self::RETRYABLE, true);
    }

    /** Текст для пользователя: код без пояснения ему ничего не скажет. */
    public function userMessage(): string
    {
        return match ($this->errorCode) {
            'no_key' => 'Ключ AI не выбран — выберите рабочий ключ в настройках ассистента.',
            'insufficient_credits' => 'Средства на ключе закончились.',
            'invalid_key' => 'Провайдер отклонил ключ — проверьте его в настройках ассистента.',
            'model_not_found' => 'Модель не найдена у провайдера — выберите другую.',
            'context_overflow' => 'Диалог не помещается в окно модели даже после сжатия — начните новый диалог.',
            'rate_limited' => 'Провайдер ограничил частоту запросов — попробуйте чуть позже.',
            'network_error' => 'Нет связи с провайдером модели.',
            'stream_error' => 'Ответ модели оборвался.',
            default => 'Ошибка провайдера: '.$this->getMessage(),
        };
    }

    public static function fromStatus(int $status, string $message, ?int $retryAfter = null): self
    {
        $code = match (true) {
            $status === 413 || ($status === 400 && self::looksLikeOverflow($message)) => 'context_overflow',
            $status === 402 => 'insufficient_credits',
            $status === 401, $status === 403 => 'invalid_key',
            $status === 404 => 'model_not_found',
            $status === 429 => 'rate_limited',
            $status === 408, $status >= 500 => 'provider_error',
            default => 'bad_request',
        };

        return new self($code, $message, $status, $retryAfter);
    }

    /**
     * Разобрать исключение HTTP-клиента. null — это не сбой провайдера, а ошибка
     * в нашем коде: повторять её бессмысленно, пусть летит дальше.
     */
    public static function classify(\Throwable $e): ?self
    {
        if ($e instanceof self) {
            return $e;
        }

        if ($e instanceof RequestException) {
            $error = $e->response->json('error');
            $message = is_array($error) ? self::providerMessage($error) : $e->getMessage();
            $retryAfter = (int) $e->response->header('Retry-After');

            return self::fromStatus($e->response->status(), $message, $retryAfter > 0 ? $retryAfter : null);
        }

        if ($e instanceof ConnectionException || $e instanceof \GuzzleHttp\Exception\TransferException) {
            return new self('network_error', $e->getMessage());
        }

        return null;
    }

    /**
     * Текст ошибки OpenRouter. Когда падает провайдер, message бывает общим
     * («Provider returned error»), а исходная причина лежит в metadata.raw —
     * без неё переполнение контекста не распознать.
     *
     * @param  array<string,mixed>  $error  тело поля error
     */
    public static function providerMessage(array $error): string
    {
        $message = (string) ($error['message'] ?? 'Ошибка провайдера');
        $raw = $error['metadata']['raw'] ?? null;

        if ($raw !== null && $raw !== '') {
            $message .= ' '.(is_string($raw) ? $raw : json_encode($raw, JSON_UNESCAPED_UNICODE));
        }

        return trim($message);
    }

    private static function looksLikeOverflow(string $message): bool
    {
        $lower = mb_strtolower($message);

        foreach (self::OVERFLOW_MARKERS as $marker) {
            if (str_contains($lower, $marker)) {
                return true;
            }
        }

        return false;
    }
}
