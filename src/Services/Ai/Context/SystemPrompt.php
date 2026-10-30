<?php

namespace Templite\Cms\Services\Ai\Context;

use Templite\Cms\Services\Ai\AiTextRepository;
use Templite\Cms\Services\Ai\EditorContext;

/** System prompt агента: базовый текст настроек + открытый редактор + конвенции + резюме. */
class SystemPrompt
{
    public function __construct(private readonly AiTextRepository $texts)
    {
    }

    public function build(string $base, ?EditorContext $ctx, ?string $summary = null): string
    {
        if ($ctx && $ctx->isBlockEditorOpen()) {
            $ctxNote = "\n\nСейчас открыт редактор типа блока #{$ctx->blockId}. Правки его кода предлагай инструментом update_block_code — они появятся в редакторе для проверки пользователем.";
        } elseif ($ctx && $ctx->isActionEditorOpen()) {
            $ctxNote = "\n\nСейчас открыт редактор action #{$ctx->actionId}. Правки его кода предлагай инструментом update_action_code — они появятся в редакторе для проверки пользователем, сохраняет он сам.";
        } elseif ($ctx && $ctx->isTemplateEditorOpen()) {
            $ctxNote = "\n\nСейчас открыт редактор шаблона #{$ctx->templateId}. Подключение библиотек к нему появится в редакторе для проверки пользователем — сохраняет их он сам.";
        } elseif ($ctx && $ctx->isSettingsEditorOpen('values')) {
            $ctxNote = "\n\nСейчас открыт экран значений глобальных настроек. Правки значений предлагай инструментом set_global_values — они появятся в форме для проверки пользователем, сохраняет он сам.";
        } elseif ($ctx && $ctx->isSettingsEditorOpen('structure')) {
            $ctxNote = "\n\nСейчас открыт экран структуры глобальных настроек. Правки структуры применяй инструментами — экран обновится сам.";
        } elseif ($ctx && $ctx->isOpen()) {
            $ctxNote = "\n\nСейчас открыт редактор страницы #{$ctx->pageId}. Правки этой страницы предлагай инструментами — они появятся в редакторе для проверки пользователем.";
        } else {
            $ctxNote = "\n\nРедактор не открыт. Изменения существующих страниц применяй напрямую, новые страницы создавай инструментом create_page.";
        }

        $prompt = $base.$ctxNote.$this->authoringConventions();

        // Журнал действий подаётся модели в истории прямо перед ответом, и без
        // оговорки она начинает дописывать такие строки в ответ сама.
        $prompt .= "\n\nСтроки вида [Действия: …] и [Ход прерван …] в истории диалога добавляет платформа как служебный журнал. Сам их не пиши — пользователь видит твои действия отдельно.";

        // Резюме — вместо сообщений, убранных из контекста сжатием.
        if ($summary) {
            $prompt .= "\n\nКраткое содержание ранней части диалога (сами сообщения из контекста убраны):\n".$summary;
        }

        return $prompt;
    }

    /**
     * Указатель на справки плюс запреты, о которые агент спотыкается чаще
     * всего.
     *
     * Сами конвенции лежат в инструментах-справках: вместе они весили больше
     * семи тысяч символов и уходили в каждый запрос, даже когда разговор шёл
     * не о вёрстке. Запреты оставлены здесь потому, что цена их нарушения —
     * отказ валидатора и потраченный ход, а справку агент может и не вызвать.
     */
    private function authoringConventions(): string
    {
        return "\n\n".trim($this->texts->get('instructions.md'));
    }
}
