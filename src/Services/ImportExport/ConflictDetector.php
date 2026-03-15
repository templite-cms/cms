<?php

namespace Templite\Cms\Services\ImportExport;

use Templite\Cms\Models\{Block, BlockType, TemplatePage, Component, Action, Library,
    BlockPreset, Page, PageType, CmsConfig, City, Language};

/**
 * Обнаружение конфликтов при импорте.
 *
 * Проверяет, какие из импортируемых сущностей уже существуют в БД,
 * чтобы пользователь мог решить что делать: пропустить, перезаписать или скопировать.
 */
class ConflictDetector
{
    /**
     * Маппинг типа сущности на [класс модели, поле-идентификатор].
     *
     * @var array<string, array{0: class-string, 1: string}>
     */
    protected array $typeMap = [
        'block' => [Block::class, 'slug'],
        'block_type' => [BlockType::class, 'slug'],
        'template' => [TemplatePage::class, 'slug'],
        'component' => [Component::class, 'slug'],
        'action' => [Action::class, 'slug'],
        'library' => [Library::class, 'slug'],
        'preset' => [BlockPreset::class, 'slug'],
        'page' => [Page::class, 'url'],
        'page_type' => [PageType::class, 'slug'],
        'cms_config' => [CmsConfig::class, 'key'],
        'city' => [City::class, 'slug'],
        'language' => [Language::class, 'code'],
    ];

    /**
     * Обнаружить конфликты между импортируемыми данными и существующими сущностями.
     *
     * @param array $manifest  Манифест из ZIP-архива
     * @param array $entitiesData  Данные сущностей: type => [identifier => data]
     * @return array Список конфликтов, каждый содержит type, identifier, name, existing_name
     */
    public function detect(array $manifest, array $entitiesData): array
    {
        $conflicts = [];

        foreach ($entitiesData as $type => $items) {
            if (!isset($this->typeMap[$type])) {
                continue;
            }

            [$modelClass, $field] = $this->typeMap[$type];
            $identifiers = array_keys($items);

            // Определяем поле с человекочитаемым именем для существующей сущности
            $nameField = match ($type) {
                'page' => 'title',
                'cms_config' => 'key',
                default => 'name',
            };

            $existing = $modelClass::whereIn($field, $identifiers)
                ->get()
                ->mapWithKeys(fn ($m) => [$m->{$field} => $m->{$nameField} ?? $m->{$field}])
                ->toArray();

            foreach ($existing as $identifier => $existingName) {
                $importedName = $items[$identifier]['name'] ?? $items[$identifier]['title'] ?? $identifier;
                $conflicts[] = [
                    'type' => $type,
                    'identifier' => $identifier,
                    'name' => $importedName,
                    'existing_name' => $existingName,
                ];
            }
        }

        return $conflicts;
    }
}
