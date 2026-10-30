<?php

namespace Templite\Cms\Services\Ai\Tools;

use Illuminate\Database\Eloquent\Model;
use Templite\Cms\Models\Block;
use Templite\Cms\Models\Library;
use Templite\Cms\Models\TemplatePage;
use Templite\Cms\Services\Ai\EditorContext;

/**
 * Подключение библиотеки к типу блока или к шаблону — и отключение обратно.
 *
 * Правка точечная: указанная библиотека добавляется или убирается, остальные
 * привязки остаются как были. Заменять набор целиком здесь нельзя — агент не
 * видит текущего состава и одним вызовом снёс бы чужие подключения.
 */
class AttachLibraryTool implements Tool
{
    public function name(): string
    {
        return 'attach_library';
    }

    public function definition(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'library_id' => ['type' => 'integer', 'description' => 'ID библиотеки из list_libraries или add_cdn_library'],
                'target' => [
                    'type' => 'string',
                    'enum' => ['block', 'template'],
                    'description' => 'Куда подключаем: block — тип блока из list_block_types, template — шаблон из list_templates',
                ],
                'target_id' => ['type' => 'integer', 'description' => 'ID типа блока или шаблона'],
                'action' => [
                    'type' => 'string',
                    'enum' => ['attach', 'detach'],
                    'description' => 'attach — подключить (по умолчанию), detach — отключить',
                ],
            ],
            'required' => ['library_id', 'target', 'target_id'],
        ];
    }

    public function execute(array $args, ?EditorContext $ctx): array
    {
        $target = (string) ($args['target'] ?? '');

        if (!in_array($target, ['block', 'template'], true)) {
            return ['error' => 'invalid_target', 'message' => 'target должен быть block или template.'];
        }

        $libraryId = (int) ($args['library_id'] ?? 0);
        $library = Library::find($libraryId);

        if (!$library) {
            return ['error' => 'library_not_found', 'library_id' => $libraryId];
        }

        $targetId = (int) ($args['target_id'] ?? 0);
        $model = $target === 'block' ? Block::find($targetId) : TemplatePage::find($targetId);

        if (!$model) {
            return [
                'error' => $target === 'block' ? 'block_not_found' : 'template_not_found',
                'target_id' => $targetId,
            ];
        }

        $attach = ($args['action'] ?? 'attach') !== 'detach';

        // Открытый редактор держит свой набор библиотек и при сохранении
        // отправляет его целиком. Запись в базу мимо редактора пережила бы
        // ровно до нажатия «Сохранить», поэтому правку отдаём в редактор
        if ($this->editorHolds($ctx, $target, $targetId)) {
            return [
                'status' => 'proposed_to_editor',
                'editor_op' => [
                    'op' => 'attach_library',
                    'payload' => [
                        'target' => $target,
                        'target_id' => $targetId,
                        'library_id' => $library->id,
                        'attached' => $attach,
                    ],
                ],
                'library' => $this->describe($library),
            ];
        }

        $changed = $attach
            // syncWithoutDetaching, а не attach: у pivot составной первичный
            // ключ, и повторное подключение уронило бы вызов ошибкой SQL
            ? (bool) $this->relation($model)->syncWithoutDetaching([$library->id])['attached']
            : $this->relation($model)->detach($library->id) > 0;

        return array_filter([
            'status' => $attach ? 'attached' : 'detached',
            // Агент не знает исходного состояния: без этого флага он не
            // отличит «подключил» от «уже было подключено»
            'changed' => $changed,
            'target' => $target,
            'target_id' => $targetId,
            'library' => $this->describe($library),
            'warning' => $attach && !$library->active
                ? 'Библиотека выключена, её файлы не попадут на страницу. Включите её в разделе «Библиотеки».'
                : null,
        ], fn ($value) => $value !== null);
    }

    /** Редактор этой же сущности открыт у пользователя. */
    private function editorHolds(?EditorContext $ctx, string $target, int $targetId): bool
    {
        if (!$ctx) {
            return false;
        }

        return $target === 'block'
            ? $ctx->isBlockEditorOpen($targetId)
            : $ctx->isTemplateEditorOpen($targetId);
    }

    /** @return \Illuminate\Database\Eloquent\Relations\BelongsToMany */
    private function relation(Model $model)
    {
        return $model->libraries();
    }

    /** @return array<string,mixed> */
    private function describe(Library $library): array
    {
        return [
            'id' => $library->id,
            'slug' => $library->slug,
            'name' => $library->name,
            'active' => (bool) $library->active,
        ];
    }
}
