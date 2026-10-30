<?php

namespace Templite\Cms\Services\Ai\Tools;

use Templite\Cms\Http\Controllers\Api\GlobalSettingsController;
use Templite\Cms\Models\GlobalField;
use Templite\Cms\Models\GlobalFieldPage;
use Templite\Cms\Models\GlobalFieldSection;
use Templite\Cms\Models\GlobalFieldValue;
use Templite\Cms\Services\Ai\EditorContext;
use Templite\Cms\Services\Ai\Settings\SettingsUsageScanner;
use Templite\Cms\Services\CacheManager;

/**
 * Удаление вкладки, секции или поля глобальных настроек — с отчётом о потерях
 * и обязательным подтверждением.
 *
 * Вызов без confirm ничего не удаляет: он возвращает, что именно погибнет.
 * Причина в том, что поле уносит с собой заполненные значения, а
 * удаление вкладки или секции вдобавок не удаляет их поля, а обнуляет им
 * global_field_page_id / global_field_section_id (оба ключа nullOnDelete):
 * из админки поля пропадают, в $global остаются.
 */
class DeleteSettingsNodeTool implements Tool
{
    use ChecksSettingsPermission;

    public function __construct(
        private readonly SettingsUsageScanner $scanner,
        private readonly CacheManager $cache,
        // Пишет в аудит-лог тем же методом, что и ручная правка через API —
        // см. комментарий у ApplySettingsLayoutTool::$auditLog. Здесь это
        // особенно важно: из трёх структурных инструментов только этот
        // необратимо уничтожает данные.
        private readonly GlobalSettingsController $auditLog,
    ) {
    }

    public function name(): string
    {
        return 'delete_settings_node';
    }

    public function definition(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'kind' => ['type' => 'string', 'enum' => ['page', 'section', 'field']],
                'id' => ['type' => 'integer', 'description' => 'ID из list_global_fields'],
                'confirm' => ['type' => 'boolean', 'description' => 'false или отсутствует — вернуть отчёт о потерях; true — удалить'],
            ],
            'required' => ['kind', 'id'],
        ];
    }

    public function execute(array $args, ?EditorContext $ctx): array
    {
        if ($deny = $this->denyIfNoSettingsPermission('settings.edit')) {
            return $deny;
        }

        $kind = (string) ($args['kind'] ?? '');
        $id = (int) ($args['id'] ?? 0);

        $node = match ($kind) {
            'page' => GlobalFieldPage::find($id),
            'section' => GlobalFieldSection::find($id),
            'field' => GlobalField::find($id),
            default => null,
        };

        if (! $node) {
            return ['error' => 'node_not_found', 'kind' => $kind, 'id' => $id];
        }

        $report = $this->report($kind, $node);

        if (($args['confirm'] ?? false) !== true) {
            return [
                'status' => 'confirmation_required',
                'message' => 'Ничего не удалено. Покажи пользователю, что будет потеряно, и вызови ещё раз с confirm=true, если он согласен.',
            ] + $report;
        }

        // Имя — до delete(): после удаления атрибуты в памяти модели остаются
        // читаемыми, но брать значение до операции нагляднее и повторяет
        // порядок строк в Api\GlobalSettingsController::destroyPage/Section/Field().
        $name = $node->name;
        $node->delete();
        $this->cache->invalidateGlobalFields();

        // Тот же журнал и тот же формат записи, что у ручного удаления через
        // Api\GlobalSettingsController — тот же 'entity', что и $kind здесь.
        $this->auditLog->logAction('delete', 'global_settings', $id, ['name' => $name, 'entity' => $kind]);

        $result = ['status' => 'deleted', 'kind' => $kind, 'id' => $id] + $report;

        // Удаление ушло прямо в базу — оба экрана настроек после него показывают
        // то, чего уже нет. Сигнал один на оба, перечитывает каждый своё.
        if ($ctx && $ctx->isSettingsEditorOpen()) {
            $result['editor_op'] = ['op' => 'refresh_settings', 'payload' => ['deleted' => $kind]];
        }

        return $result;
    }

    /** @return array<string,mixed> */
    private function report(string $kind, mixed $node): array
    {
        $fields = match ($kind) {
            'page' => GlobalField::where('global_field_page_id', $node->id)->get(),
            'section' => GlobalField::where('global_field_section_id', $node->id)->get(),
            'field' => GlobalField::where('id', $node->id)->orWhere('parent_id', $node->id)->get(),
        };

        $fieldIds = $fields->pluck('id')->all();

        // Поля переживают удаление и вкладки, и секции: оба внешних ключа
        // (global_field_page_id, global_field_section_id) объявлены
        // nullOnDelete. Каскад доходит до значений только тогда,
        // когда удаляют само поле, — в остальных случаях терять нечего.
        $fieldsSurvive = $kind !== 'field';

        $valueIds = $fieldsSurvive
            ? []
            : GlobalFieldValue::whereIn('global_field_id', $fieldIds)->pluck('id')->all();

        $usage = [];

        foreach ($fields as $field) {
            foreach ($this->scanner->find($field->key) as $path) {
                $usage[$field->key][] = $path;
            }
        }

        $report = [
            'name' => $node->name,
            'fields' => $fields->pluck('key')->all(),
            'values_lost' => count($valueIds),
            'used_in_code' => $usage ?: null,
        ];

        if ($kind === 'page') {
            $report['sections_deleted'] = GlobalFieldSection::where('global_field_page_id', $node->id)->count();
            $report['orphan_warning'] = 'Перечисленные в fields поля не удалятся, а останутся без вкладки: со всех экранов админки они пропадут, но в $global и на страницах сайта продолжат отдавать значения. Чтобы удалить их насовсем, удаляй поля по одному.';
        }

        if ($kind === 'section') {
            // Секция уходит одна: её поля остаются на вкладке, но без секции —
            // это ровно те detached_fields, которые показывает list_global_fields.
            $report['orphan_warning'] = 'Перечисленные в fields поля не удалятся, а останутся на вкладке без секции: с экранов админки они пропадут, но в $global и на страницах сайта продолжат отдавать значения. Вернуть их в раскладку можно через apply_settings_layout, указав ту же секцию; чтобы удалить насовсем — удаляй поля по одному.';
        }

        return array_filter($report, fn ($value) => $value !== null);
    }
}
