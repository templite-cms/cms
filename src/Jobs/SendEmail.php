<?php

namespace Templite\Cms\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Job: отправка email.
 *
 * Универсальный Job для отправки писем из CMS:
 * - Уведомления при отправке форм (Leads)
 * - Уведомления о заказах (Shop)
 * - Системные уведомления
 */
class SendEmail implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Количество попыток.
     */
    public int $tries = 3;

    /**
     * Таймаут выполнения (секунды).
     */
    public int $timeout = 30;

    /**
     * Задержка между повторами (секунды).
     */
    public array $backoff = [10, 30, 60];

    /**
     * Create a new job instance.
     *
     * @param string $to Email получателя
     * @param string $subject Тема письма
     * @param string $body Тело письма (HTML)
     * @param string|null $from Email отправителя (null = из конфига)
     * @param string|null $fromName Имя отправителя
     * @param array $attachments Вложения [['path' => ..., 'name' => ..., 'mime' => ...]]
     * @param array $headers Дополнительные заголовки
     * @param string|null $replyTo Reply-To адрес
     */
    public function __construct(
        protected string $to,
        protected string $subject,
        protected string $body,
        protected ?string $from = null,
        protected ?string $fromName = null,
        protected array $attachments = [],
        protected array $headers = [],
        protected ?string $replyTo = null,
    ) {
        $this->onQueue('email');
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        try {
            Mail::html($this->body, function ($message) {
                $message->to($this->to);
                $message->subject($this->subject);

                // Отправитель
                if ($this->from) {
                    $message->from($this->from, $this->fromName ?? config('mail.from.name'));
                }

                // Reply-To
                if ($this->replyTo) {
                    $message->replyTo($this->replyTo);
                }

                // Вложения
                foreach ($this->attachments as $attachment) {
                    if (isset($attachment['path']) && file_exists($attachment['path'])) {
                        $message->attach($attachment['path'], [
                            'as' => $attachment['name'] ?? basename($attachment['path']),
                            'mime' => $attachment['mime'] ?? null,
                        ]);
                    }
                }

                // Дополнительные заголовки
                foreach ($this->headers as $name => $value) {
                    $message->getHeaders()->addTextHeader($name, $value);
                }
            });

            Log::info("SendEmail: письмо отправлено на {$this->to}, тема: {$this->subject}");
        } catch (\Throwable $e) {
            Log::error("SendEmail: ошибка отправки на {$this->to}: {$e->getMessage()}");
            throw $e;
        }
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error("SendEmail: не удалось отправить письмо на {$this->to} после {$this->tries} попыток: {$exception->getMessage()}");
    }

    /**
     * Создать Job для уведомления о лиде.
     */
    public static function forLead(string $to, string $formName, array $data): self
    {
        $body = '<h2>Новая заявка: ' . e($formName) . '</h2><table>';
        foreach ($data as $key => $value) {
            $body .= '<tr><td><strong>' . e($key) . ':</strong></td><td>' . e($value) . '</td></tr>';
        }
        $body .= '</table>';

        return new self(
            to: $to,
            subject: "Новая заявка: {$formName}",
            body: $body,
        );
    }

    /**
     * Создать Job для уведомления о заказе.
     */
    public static function forOrder(string $to, string $orderNumber, string $status, float $total): self
    {
        $body = '<h2>Заказ ' . e($orderNumber) . '</h2>';
        $body .= '<p>Статус: <strong>' . e($status) . '</strong></p>';
        $body .= '<p>Сумма: <strong>' . number_format($total, 2, '.', ' ') . ' руб.</strong></p>';

        return new self(
            to: $to,
            subject: "Заказ {$orderNumber} - {$status}",
            body: $body,
        );
    }
}
