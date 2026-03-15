<?php

namespace Templite\Cms\Actions;

use Illuminate\Support\Facades\Validator;
use Templite\Cms\Contracts\ActionContext;
use Templite\Cms\Contracts\BlockActionInterface;
use Templite\Cms\Jobs\SendEmail;

/**
 * Action: Обработчик форм.
 *
 * Универсальный обработчик POST-данных форм.
 * Валидация, сохранение, отправка email-уведомлений.
 * Применение: формы обратной связи, подписки, заявки.
 */
class FormHandler implements BlockActionInterface
{
    public function params(): array
    {
        return [
            'fields' => 'array',
            'notify_email' => 'string|nullable',
            'notify_subject' => 'string|nullable',
            'success_message' => 'string',
            'save_to_db' => 'boolean',
        ];
    }

    public function returns(): array
    {
        return [
            'success' => 'boolean',
            'message' => 'string',
            'errors' => 'array|nullable',
        ];
    }

    public function handle(array $params, ActionContext $context): array
    {
        $fields = $params['fields'] ?? [];
        $request = $context->request;

        // 1. Валидация
        $rules = $this->buildValidationRules($fields);
        $messages = $this->buildValidationMessages($fields);

        $validator = Validator::make($request->all(), $rules, $messages);

        if ($validator->fails()) {
            return [
                'success' => false,
                'message' => 'Проверьте правильность заполнения полей.',
                'errors' => $validator->errors()->toArray(),
            ];
        }

        $data = $validator->validated();

        // 2. Сохранение в БД (если модуль Leads подключён)
        if (($params['save_to_db'] ?? true) && class_exists(\Templite\Crm\Models\Lead::class)) {
            $formHash = $context->blockData['_form_hash'] ?? null;
            if ($formHash) {
                $form = \Templite\Crm\Models\LeadForm::where('hash', $formHash)->first();
                if ($form) {
                    \Templite\Crm\Models\Lead::create([
                        'form_id' => $form->id,
                        'status_id' => \Templite\Crm\Models\LeadStatus::getDefault()?->id,
                        'data' => $data,
                        'ip' => $request->ip(),
                        'user_agent' => $request->userAgent(),
                        'page_url' => $request->header('Referer'),
                    ]);
                }
            }
        }

        // 3. Отправка email-уведомления
        if (!empty($params['notify_email'])) {
            SendEmail::dispatch(
                to: $params['notify_email'],
                subject: $params['notify_subject'] ?? 'Новая заявка с сайта',
                body: $this->buildEmailBody($data, $fields, $context),
            );
        }

        return [
            'success' => true,
            'message' => $params['success_message'] ?? 'Спасибо! Ваша заявка принята.',
            'errors' => null,
        ];
    }

    /**
     * Построение правил валидации из описания полей.
     */
    protected function buildValidationRules(array $fields): array
    {
        $rules = [];

        foreach ($fields as $field) {
            $name = $field['name'] ?? $field['key'] ?? null;
            if (!$name) continue;

            $fieldRules = [];

            if ($field['required'] ?? false) {
                $fieldRules[] = 'required';
            } else {
                $fieldRules[] = 'nullable';
            }

            $type = $field['type'] ?? 'text';
            match ($type) {
                'email' => $fieldRules[] = 'email',
                'phone', 'tel' => $fieldRules[] = 'string|max:30',
                'number' => $fieldRules[] = 'numeric',
                'textarea', 'text' => $fieldRules[] = 'string|max:5000',
                'file' => $fieldRules[] = 'file|max:10240',
                'checkbox' => $fieldRules[] = 'boolean',
                default => $fieldRules[] = 'string|max:1000',
            };

            $rules[$name] = implode('|', $fieldRules);
        }

        return $rules;
    }

    /**
     * Построение кастомных сообщений валидации.
     */
    protected function buildValidationMessages(array $fields): array
    {
        $messages = [];

        foreach ($fields as $field) {
            $name = $field['name'] ?? $field['key'] ?? null;
            $label = $field['label'] ?? $name;
            if (!$name) continue;

            $messages["{$name}.required"] = "Поле \"{$label}\" обязательно для заполнения.";
            $messages["{$name}.email"] = "Введите корректный email-адрес.";
        }

        return $messages;
    }

    /**
     * Построение тела email-уведомления.
     */
    protected function buildEmailBody(array $data, array $fields, ActionContext $context): string
    {
        $body = '<h2>Новая заявка с сайта</h2>';
        $body .= '<p>Страница: ' . e($context->page->title ?? 'Неизвестно') . '</p>';
        $body .= '<table border="1" cellpadding="8" cellspacing="0">';

        foreach ($data as $key => $value) {
            $label = $key;
            foreach ($fields as $field) {
                if (($field['name'] ?? $field['key'] ?? null) === $key) {
                    $label = $field['label'] ?? $key;
                    break;
                }
            }
            $body .= '<tr><td><strong>' . e($label) . '</strong></td><td>' . e($value) . '</td></tr>';
        }

        $body .= '</table>';
        $body .= '<p><small>Отправлено: ' . now()->format('d.m.Y H:i:s') . '</small></p>';

        return $body;
    }
}
