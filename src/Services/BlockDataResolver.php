<?php

namespace Templite\Cms\Services;

use Illuminate\Support\Collection;
use Templite\Cms\Models\Block;
use Templite\Cms\Models\BlockField;
use Templite\Cms\Models\File;
use Templite\Cms\Models\Page;
use Templite\Cms\Models\PageBlock;

class BlockDataResolver
{
    /**
     * Зарегистрированные дополнительные типы (из Shop и т.п.).
     */
    protected array $typeResolvers = [];

    /**
     * Зарегистрировать резолвер для дополнительного типа поля.
     */
    public function registerType(string $type, callable $resolver): void
    {
        $this->typeResolvers[$type] = $resolver;
    }

    /**
     * Резолвить все блоки страницы за минимум запросов.
     */
    public function resolvePageBlocks(Collection $pageBlocks): void
    {
        // 1. Собираем все ID из всех блоков
        $fileIds = [];
        $pageIds = [];
        $extraIds = []; // category_id, product_id и т.п.

        foreach ($pageBlocks as $pb) {
            $fields = $pb->block->fields ?? collect();
            $data = $this->mergePresetData($pb);
            $this->collectIds($fields, $data, $fileIds, $pageIds, $extraIds);
        }

        // 2. Загружаем все связанные сущности за минимум запросов
        $files = !empty($fileIds)
            ? File::whereIn('id', array_unique($fileIds))->get()->keyBy('id')
            : collect();

        $pages = !empty($pageIds)
            ? Page::whereIn('id', array_unique($pageIds))->get()->keyBy('id')
            : collect();

        // Загружаем дополнительные типы
        $extraEntities = [];
        foreach ($extraIds as $type => $ids) {
            if (isset($this->typeResolvers[$type]) && !empty($ids)) {
                $resolver = $this->typeResolvers[$type];
                $uniqueIds = array_unique($ids);
                $extraEntities[$type] = collect();
                foreach ($uniqueIds as $id) {
                    $entity = $resolver($id);
                    if ($entity) {
                        $extraEntities[$type][$id] = $entity;
                    }
                }
            }
        }

        // 3. Подставляем объекты вместо ID
        foreach ($pageBlocks as $pb) {
            $fields = $pb->block->fields ?? collect();
            $data = $this->mergePresetData($pb);

            $pb->resolved_data = $this->resolveData(
                $fields,
                $data,
                $files,
                $pages,
                $extraEntities
            );
        }
    }

    /**
     * Резолвить данные одного блока.
     */
    public function resolveBlockData(Block $block, array $data): array
    {
        $fields = $block->fields ?? collect();

        // Собираем ID
        $fileIds = [];
        $pageIds = [];
        $extraIds = [];
        $this->collectIds($fields, $data, $fileIds, $pageIds, $extraIds);

        // Загружаем
        $files = !empty($fileIds)
            ? File::whereIn('id', array_unique($fileIds))->get()->keyBy('id')
            : collect();

        $pages = !empty($pageIds)
            ? Page::whereIn('id', array_unique($pageIds))->get()->keyBy('id')
            : collect();

        $extraEntities = [];
        foreach ($extraIds as $type => $ids) {
            if (isset($this->typeResolvers[$type]) && !empty($ids)) {
                $resolver = $this->typeResolvers[$type];
                $extraEntities[$type] = collect();
                foreach (array_unique($ids) as $id) {
                    $entity = $resolver($id);
                    if ($entity) {
                        $extraEntities[$type][$id] = $entity;
                    }
                }
            }
        }

        return $this->resolveData($fields, $data, $files, $pages, $extraEntities);
    }

    /**
     * Собрать все ID из данных блока для пакетной загрузки.
     */
    protected function collectIds(
        Collection $fields,
        array $data,
        array &$fileIds,
        array &$pageIds,
        array &$extraIds
    ): void {
        foreach ($fields as $field) {
            $key = $field->key;
            $value = $data[$key] ?? null;

            if ($value === null) {
                continue;
            }

            if ($field->type === 'img' || $field->type === 'file') {
                if (is_numeric($value)) {
                    $fileIds[] = (int) $value;
                }
            } elseif ($field->type === 'page') {
                if (is_numeric($value)) {
                    $pageIds[] = (int) $value;
                }
            } elseif ($field->type === 'user') {
                if (is_numeric($value)) {
                    $extraIds['user'][] = (int) $value;
                }
            } elseif (in_array($field->type, ['category', 'product', 'product_option'])) {
                if (is_numeric($value)) {
                    $extraIds[$field->type][] = (int) $value;
                }
            } elseif ($field->type === 'array' && is_array($value)) {
                // Рекурсивная обработка повторителя
                $childFields = $field->children ?? collect();
                if ($childFields->isNotEmpty()) {
                    foreach ($value as $item) {
                        if (is_array($item)) {
                            $this->collectIds($childFields, $item, $fileIds, $pageIds, $extraIds);
                        }
                    }
                }
            }
        }
    }

    /**
     * Подставить объекты вместо ID и гарантировать типизированные значения по умолчанию.
     */
    protected function resolveData(
        Collection $fields,
        array $data,
        Collection $files,
        Collection $pages,
        array $extraEntities
    ): array {
        $resolved = $data;

        foreach ($fields as $field) {
            $key = $field->key;
            $value = $data[$key] ?? null;

            // Гарантируем типизированное значение по умолчанию для каждого поля
            if ($value === null || $value === '') {
                $resolved[$key] = $this->getDefaultForType($field);
                continue;
            }

            if ($field->type === 'img' || $field->type === 'file') {
                $resolved[$key] = is_numeric($value) ? ($files[$value] ?? null) : null;
            } elseif ($field->type === 'page') {
                $resolved[$key] = is_numeric($value) ? ($pages[$value] ?? null) : null;
            } elseif ($field->type === 'user') {
                $resolved[$key] = is_numeric($value) ? ($extraEntities['user'][$value] ?? null) : null;
            } elseif (in_array($field->type, ['category', 'product', 'product_option'])) {
                $type = $field->type;
                $resolved[$key] = is_numeric($value) ? ($extraEntities[$type][$value] ?? null) : null;
            } elseif ($field->type === 'link') {
                // Нормализация: строка → объект {url, text, target}
                if (is_string($value)) {
                    $resolved[$key] = ['url' => $value, 'text' => '', 'target' => '_self'];
                } elseif (is_array($value)) {
                    $resolved[$key] = array_merge(['url' => '', 'text' => '', 'target' => '_self'], $value);
                }
            } elseif ($field->type === 'array') {
                if (is_array($value)) {
                    $childFields = $field->children ?? collect();
                    if ($childFields->isNotEmpty()) {
                        $resolved[$key] = array_map(function ($item) use ($childFields, $files, $pages, $extraEntities) {
                            if (is_array($item)) {
                                return $this->resolveData($childFields, $item, $files, $pages, $extraEntities);
                            }
                            return $item;
                        }, $value);
                    }
                } else {
                    $resolved[$key] = [];
                }
            }
        }

        return $resolved;
    }

    /**
     * Мержить данные блока с данными глобального пресета.
     */
    protected function mergePresetData(PageBlock $pb): array
    {
        $data = $pb->data ?? [];

        if ($pb->preset_id && $pb->preset && $pb->preset->type === 'global') {
            $presetData = $pb->preset->data ?? [];
            $overrides = $pb->field_overrides ?? [];
            $merged = $presetData;
            foreach ($overrides as $key => $isOverridden) {
                if ($isOverridden && array_key_exists($key, $data)) {
                    $merged[$key] = $data[$key];
                }
            }
            return $merged;
        }

        return $data;
    }

    /**
     * Получить значение по умолчанию для типа поля.
     *
     * Гарантирует, что шаблон всегда получает корректный тип данных,
     * даже если поле ещё не заполнено.
     */
    protected function getDefaultForType(BlockField $field): mixed
    {
        // Если у поля задан default_value в БД — используем его (с приведением типа)
        if ($field->default_value !== null && $field->default_value !== '') {
            return \Templite\Cms\Support\FieldValueCaster::cast(
                $field->default_value,
                $field->type
            );
        }

        return match ($field->type) {
            'array'                               => [],
            'checkbox'                            => false,
            'number'                              => 0,
            'link'                                => ['url' => '', 'text' => '', 'target' => '_self'],
            'img', 'file', 'page', 'user',
            'category', 'product', 'product_option' => null,
            // text, textfield, editor, html, color, date, datetime, select, radio
            default                               => '',
        };
    }
}
