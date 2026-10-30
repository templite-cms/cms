<?php

namespace Templite\Cms\Services\Ai;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Templite\Cms\Exceptions\UnsupportedAiProviderException;
use Templite\Cms\Models\AiKey;

/**
 * Клиент OpenRouter. Ключ приходит снаружи: он принадлежит менеджеру,
 * а не установке CMS.
 */
class OpenRouterClient
{
    private const BASE_URL = 'https://openrouter.ai/api/v1';

    private function __construct(private readonly string $apiKey)
    {
    }

    public static function forKey(AiKey $key): self
    {
        if ($key->provider !== 'openrouter') {
            throw new UnsupportedAiProviderException($key->provider);
        }

        return new self((string) $key->api_key);
    }

    /** Каталог моделей публичный — ключ для него не нужен. */
    public static function catalog(): self
    {
        return new self('');
    }

    /**
     * SSE-стрим одного шага чата: отдаёт поток построчно, строки разбирает StepStreamer.
     *
     * @param  array<string,mixed>  $payload  (model, messages, tools, tool_choice, ...)
     * @return iterable<string>
     */
    public function chatStream(array $payload): iterable
    {
        $response = $this->request()
            // Общего таймаута нет — модель может думать минутами. read_timeout
            // 120 — лимит тишины между байтами. Соединение и ожидание
            // заголовков ограничены default_socket_timeout из php.ini.
            ->timeout(0)
            ->withHeaders(['Accept' => 'text/event-stream'])
            ->withOptions(['stream' => true, 'read_timeout' => 120])
            ->post(self::BASE_URL.'/chat/completions', $payload + ['stream' => true])
            ->throw();

        $body = $response->toPsrResponse()->getBody();

        $line = '';

        try {
            while (! $body->eof()) {
                $byte = $body->read(1);
                $line .= $byte;

                if ($byte === "\n") {
                    yield $line;
                    $line = '';
                }
            }
        } catch (\RuntimeException $e) {
            throw new \Templite\Cms\Exceptions\AiRequestException('stream_error', $e->getMessage());
        }

        if ($line !== '') {
            yield $line;
        }
    }

    /**
     * Обычный запрос без потока и инструментов — для служебных вызовов вроде
     * сжатия истории.
     *
     * @param  array<string,mixed>  $payload
     */
    public function complete(array $payload): string
    {
        try {
            // Служебный запрос идёт без потока и тиков: дольше сторожа молчать нельзя.
            $response = $this->request()
                ->timeout(120)
                ->post(self::BASE_URL.'/chat/completions', $payload + ['stream' => false])
                ->throw()
                ->json();
        } catch (\Throwable $e) {
            throw \Templite\Cms\Exceptions\AiRequestException::classify($e) ?? $e;
        }

        return (string) ($response['choices'][0]['message']['content'] ?? '');
    }

    /** Окно модели в токенах из каталога; null — модель в каталоге не найдена. */
    public function contextLength(string $model): ?int
    {
        foreach ($this->allModels() as $m) {
            if (($m['id'] ?? null) === $model) {
                return isset($m['context_length']) ? (int) $m['context_length'] : null;
            }
        }

        return null;
    }

    /**
     * Генерация изображения — обычный JSON-запрос, ответ приходит целиком.
     *
     * Картинка лежит в data[0]; наружу отдаём её плоско вместе с ценой,
     * чтобы вызывающий код не разбирал формат OpenRouter.
     *
     * @param  array<string,mixed>  $payload  (prompt, aspect_ratio)
     * @return array<string,mixed>
     */
    public function generateImage(array $payload, ?string $model = null): array
    {
        $response = $this->request()
            // Генерация занимает десятки секунд — дольше обычного запроса.
            ->timeout(180)
            ->post(self::BASE_URL.'/images', $payload + ['model' => $model ?: AiKey::DEFAULT_IMAGE_MODEL])
            ->throw()
            ->json() ?? [];

        $image = $response['data'][0] ?? [];

        return [
            'b64_json' => $image['b64_json'] ?? null,
            'media_type' => $image['media_type'] ?? 'image/png',
            'cost' => $response['usage']['cost'] ?? null,
        ];
    }

    /** Остаток средств по ключу в долларах. */
    public function credits(): ?float
    {
        $res = $this->request()->timeout(15)->get(self::BASE_URL.'/credits');

        if (! $res->successful()) {
            return null;
        }

        $data = $res->json('data') ?? [];
        $total = $data['total_credits'] ?? null;
        $used = $data['total_usage'] ?? null;

        return $total === null ? null : round((float) $total - (float) $used, 4);
    }

    /**
     * Модели, пригодные для ассистента. Без поддержки инструментов агент
     * бесполезен — он весь построен на них, поэтому такие модели отсеиваются.
     *
     * Сортировка по agentic-индексу Artificial Analysis: у нас агент, который
     * ходит инструментами, и именно этот показатель ближе всего к делу.
     * Модели без оценки уходят вниз — судить о них нечем.
     *
     * @return array<int,array<string,mixed>>
     */
    public function toolModels(): array
    {
        return collect($this->allModels())
            ->filter(fn ($m) => in_array('tools', $m['supported_parameters'] ?? [], true))
            ->map(fn ($m) => $this->shortModel($m))
            ->sortByDesc(fn ($m) => [$m['agentic_index'] ?? -1, $m['intelligence_index'] ?? -1])
            ->values()
            ->all();
    }

    /**
     * Модели, умеющие выдавать картинку.
     *
     * @return array<int,array<string,mixed>>
     */
    public function imageModels(): array
    {
        return collect($this->allModels())
            ->filter(fn ($m) => in_array('image', $m['architecture']['output_modalities'] ?? [], true))
            ->reject(fn ($m) => str_starts_with($m['id'], 'openrouter/'))
            ->map(fn ($m) => $this->shortModel($m))
            ->sortBy('name')
            ->values()
            ->all();
    }

    /**
     * Каталог моделей. Пустой результат сбоя загрузки не кэшируется — иначе
     * временная недоступность OpenRouter на час оставила бы каталог пустым.
     *
     * @return array<int,array<string,mixed>>
     */
    private function allModels(): array
    {
        $cached = Cache::get('cms.ai.models');

        if (is_array($cached) && $cached !== []) {
            return $cached;
        }

        $res = Http::timeout(20)->acceptJson()->get(self::BASE_URL.'/models');
        $data = $res->successful() ? ($res->json('data') ?? []) : [];

        if ($data !== []) {
            Cache::put('cms.ai.models', $data, 3600);
        }

        return $data;
    }

    /**
     * @param  array<string,mixed>  $m
     * @return array<string,mixed>
     */
    private function shortModel(array $m): array
    {
        $bench = $m['benchmarks']['artificial_analysis'] ?? [];

        return [
            'id' => $m['id'],
            'name' => $m['name'] ?? $m['id'],
            'context_length' => $m['context_length'] ?? null,
            'prompt_price' => (float) ($m['pricing']['prompt'] ?? 0) * 1_000_000,
            'completion_price' => (float) ($m['pricing']['completion'] ?? 0) * 1_000_000,
            // Генерация тарифицируется токенами: у Gemini одна картинка — около
            // 1290 токенов. Точное число зависит от размера, поэтому цена оценочная.
            'image_price' => isset($m['pricing']['image_output'])
                ? round((float) $m['pricing']['image_output'] * 1290, 4)
                : null,
            'agentic_index' => $bench['agentic_index'] ?? null,
            'intelligence_index' => $bench['intelligence_index'] ?? null,
            'coding_index' => $bench['coding_index'] ?? null,
        ];
    }

    private function request(): PendingRequest
    {
        return Http::withToken($this->apiKey)
            ->withHeaders([
                // OpenRouter показывает эти поля в статистике аккаунта.
                'HTTP-Referer' => config('app.url'),
                'X-Title' => 'Templite CMS',
            ])
            ->timeout(300)
            ->acceptJson();
    }
}
