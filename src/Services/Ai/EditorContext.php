<?php

namespace Templite\Cms\Services\Ai;

class EditorContext
{
    /**
     * @param  'page'|'block'|'template'|'action'|'settings'|null  $kind  что открыто в редакторе
     * @param  array<string,mixed>|null  $page  снапшот открытой страницы
     * @param  array<int,array<string,mixed>>  $blocks  живые блоки страницы
     * @param  array<string,string>|null  $code  живой код открытого типа блока
     */
    public function __construct(
        public readonly ?string $kind = null,
        public readonly ?int $pageId = null,
        public readonly ?array $page = null,
        public readonly array $blocks = [],
        public readonly ?int $blockId = null,
        public readonly ?array $code = null,
        public readonly ?int $templateId = null,
        public readonly ?int $actionId = null,
        public readonly ?string $settingsScreen = null,
        /** @var array<string,mixed> несохранённые значения формы настроек */
        public readonly array $settingsValues = [],
    ) {
    }

    /** Открыт редактор страницы. */
    public function isOpen(): bool
    {
        return $this->kind === 'page' && $this->pageId !== null;
    }

    /** Открыт редактор типа блока (при указанном $blockId — именно этого). */
    public function isBlockEditorOpen(?int $blockId = null): bool
    {
        if ($this->kind !== 'block' || $this->blockId === null) {
            return false;
        }

        return $blockId === null || $this->blockId === $blockId;
    }

    /** Открыт редактор action (при указанном $actionId — именно этого). */
    public function isActionEditorOpen(?int $actionId = null): bool
    {
        if ($this->kind !== 'action' || $this->actionId === null) {
            return false;
        }

        return $actionId === null || $this->actionId === $actionId;
    }

    /** Несохранённый PHP-код из открытого редактора action. */
    public function actionCode(): ?string
    {
        return $this->code['php'] ?? null;
    }

    /** Открыт редактор шаблона (при указанном $templateId — именно этого). */
    public function isTemplateEditorOpen(?int $templateId = null): bool
    {
        if ($this->kind !== 'template' || $this->templateId === null) {
            return false;
        }

        return $templateId === null || $this->templateId === $templateId;
    }

    /**
     * Открыт экран глобальных настроек.
     *
     * @param  'values'|'structure'|null  $screen  проверить конкретный экран
     */
    public function isSettingsEditorOpen(?string $screen = null): bool
    {
        if ($this->kind !== 'settings' || $this->settingsScreen === null) {
            return false;
        }

        return $screen === null || $this->settingsScreen === $screen;
    }

    /** @param array<string,mixed>|null $raw */
    public static function fromRequest(?array $raw): ?self
    {
        if (! $raw) {
            return null;
        }

        if (($raw['kind'] ?? null) === 'block' && isset($raw['block_id'])) {
            return new self(
                kind: 'block',
                blockId: (int) $raw['block_id'],
                code: $raw['code'] ?? null,
            );
        }

        // Редактор action держит код в несохранённой форме и отправляет его
        // только по кнопке: запись на диск мимо редактора прожила бы до
        // нажатия «Сохранить». Новый action без id контекстом не считается —
        // привязывать правку не к чему.
        if (($raw['kind'] ?? null) === 'action' && !empty($raw['action_id'])) {
            return new self(
                kind: 'action',
                code: $raw['code'] ?? null,
                actionId: (int) $raw['action_id'],
            );
        }

        // Редактор шаблона держит несохранённый набор библиотек и при
        // сохранении отправляет его целиком. Без этой ветки привязка,
        // сделанная агентом в обход редактора, пропала бы молча — её
        // затёрло бы то, что лежит в форме у пользователя
        if (($raw['kind'] ?? null) === 'template' && isset($raw['template_id'])) {
            return new self(
                kind: 'template',
                // Код редактор шаблона держит в форме — без него инструменты
                // читали бы с диска версию, которой пользователь уже не видит.
                code: $raw['code'] ?? null,
                templateId: (int) $raw['template_id'],
            );
        }

        // Экран значений держит всю форму в памяти и сохраняет её разом:
        // запись в базу мимо формы пользователь затрёт, нажав «Сохранить».
        // Экран структуры, наоборот, пишет каждое действие сразу — ему
        // достаточно знать, что он открыт, чтобы перечитать себя после правки.
        if (($raw['kind'] ?? null) === 'settings' && in_array($raw['screen'] ?? null, ['values', 'structure'], true)) {
            return new self(
                kind: 'settings',
                settingsScreen: $raw['screen'],
                settingsValues: $raw['values'] ?? [],
            );
        }

        // Редактор страницы: kind может отсутствовать — клиент слайса A его не слал.
        if (! isset($raw['page_id'])) {
            return null;
        }

        return new self(
            kind: 'page',
            pageId: (int) $raw['page_id'],
            page: $raw['page'] ?? null,
            blocks: $raw['blocks'] ?? [],
        );
    }
}
