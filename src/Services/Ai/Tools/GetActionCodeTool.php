<?php

namespace Templite\Cms\Services\Ai\Tools;

use Templite\Cms\Models\Action;
use Templite\Cms\Services\Ai\EditorContext;

class GetActionCodeTool implements Tool
{
    public function name(): string
    {
        return 'get_action_code';
    }

    public function definition(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'action_id' => ['type' => 'integer', 'description' => 'ID action из list_actions'],
            ],
            'required' => ['action_id'],
        ];
    }

    public function execute(array $args, ?EditorContext $ctx): array
    {
        $actionId = (int) ($args['action_id'] ?? 0);
        $action = Action::find($actionId);

        if (!$action) {
            return ['error' => 'action_not_found', 'action_id' => $actionId];
        }

        // Открытый редактор держит несохранённый код: с диска пришла бы прошлая
        // версия, и агент правил бы не то, что видит перед собой пользователь
        $live = $ctx && $ctx->isActionEditorOpen($actionId) ? $ctx->actionCode() : null;

        return array_filter([
            'action_id' => $action->id,
            'slug' => $action->slug,
            'source' => $action->source,
            'editable' => $action->source === 'storage',
            'from_editor' => $live !== null ? true : null,
            'code' => $live ?? $this->fromDisk($action),
            'params' => $action->params,
            'returns' => $action->returns,
        ], fn ($value) => $value !== null);
    }

    /** Код по принципу трёх источников: storage, затем файл класса через Reflection. */
    private function fromDisk(Action $action): ?string
    {
        $path = storage_path('cms/actions/' . basename($action->slug) . '.php');

        if (is_file($path)) {
            return (string) file_get_contents($path);
        }

        if (!$action->class_name || !class_exists($action->class_name)) {
            return null;
        }

        try {
            $file = (new \ReflectionClass($action->class_name))->getFileName();
        } catch (\ReflectionException) {
            return null;
        }

        return $file && is_file($file) ? (string) file_get_contents($file) : null;
    }
}
