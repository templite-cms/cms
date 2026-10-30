<?php

namespace Templite\Cms\Services\Ai\Tools;

use Templite\Cms\Services\Ai\EditorContext;
use Templite\Cms\Services\Ai\Settings\SettingsTree;

/**
 * Глобальные поля сайта: структура вкладок и текущие значения одним вызовом.
 *
 * Два инструмента (отдельно структура, отдельно значения) стоили бы агенту
 * двух шагов из лимита там, где нужны оба ответа сразу: чтобы вставить в
 * вёрстку $global['phone'], надо знать и что ключ заведён, и что он заполнен.
 */
class ListGlobalFieldsTool implements Tool
{
    use ChecksSettingsPermission;

    public function __construct(private readonly SettingsTree $tree)
    {
    }

    public function name(): string
    {
        return 'list_global_fields';
    }

    public function definition(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'page' => ['type' => 'string', 'description' => 'Название или id вкладки — если нужна одна, а не все'],
                'with_values' => ['type' => 'boolean', 'description' => 'Отдавать текущие значения (по умолчанию да)'],
            ],
        ];
    }

    public function execute(array $args, ?EditorContext $ctx): array
    {
        if ($deny = $this->denyIfNoSettingsPermission('settings.view')) {
            return $deny;
        }

        $pageRef = $args['page'] ?? null;

        if (is_string($pageRef) && ctype_digit($pageRef)) {
            $pageRef = (int) $pageRef;
        }

        // Экран /cms/settings сохраняет форму целиком и не пишет в БД по ходу
        // правки — не наложить его снапшот значит показать агенту то, что уже
        // устарело в момент, когда пользователь печатает.
        $unsaved = ($ctx && $ctx->isSettingsEditorOpen('values')) ? $ctx->settingsValues : [];

        return $this->tree->build($pageRef, ($args['with_values'] ?? true) !== false, $unsaved);
    }
}
