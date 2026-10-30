<?php

namespace Templite\Cms\Services\Ai\Tools;

use Templite\Cms\Models\TemplatePage;
use Templite\Cms\Services\Ai\EditorContext;

class GetTemplateCodeTool implements Tool
{
    public function name(): string
    {
        return 'get_template_code';
    }

    public function definition(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'template_id' => ['type' => 'integer', 'description' => 'ID шаблона из list_templates'],
            ],
            'required' => ['template_id'],
        ];
    }

    public function execute(array $args, ?EditorContext $ctx): array
    {
        $templateId = (int) ($args['template_id'] ?? 0);
        $template = TemplatePage::with('fields')->find($templateId);

        if (!$template) {
            return ['error' => 'template_not_found', 'template_id' => $templateId];
        }

        // Открытый редактор держит несохранённый код: с диска пришла бы прошлая
        // версия, и агент правил бы не то, что видит перед собой пользователь.
        $live = $ctx && $ctx->isTemplateEditorOpen($templateId) ? $ctx->code : null;

        return array_filter([
            'template_id' => $template->id,
            'slug' => $template->slug,
            'name' => $template->name,
            'from_editor' => $live ? true : null,
            'code' => $live ?? $this->fromDisk($template),
            // Поля шаблона доступны в его Blade как $fields['ключ'] —
            // без списка агент их не увидит и напишет вёрстку вслепую.
            'fields' => $template->fields
                ->map(fn ($f) => ['key' => $f->key, 'name' => $f->name, 'type' => $f->type])
                ->all(),
        ], fn ($value) => $value !== null);
    }

    /** @return array<string,string> */
    private function fromDisk(TemplatePage $template): array
    {
        $path = storage_path('cms/templates/' . basename($template->slug));

        $read = function (string $file) use ($path): string {
            $full = $path . '/' . $file;

            return is_file($full) ? (string) file_get_contents($full) : '';
        };

        return [
            'template' => $read('template.blade.php'),
            // Стили лежат либо в scss, либо в css — контроллер кода пишет scss,
            // но у старых шаблонов встречается css.
            'style' => $read('style.scss') ?: $read('style.css'),
            'script' => $read('script.js'),
        ];
    }
}
