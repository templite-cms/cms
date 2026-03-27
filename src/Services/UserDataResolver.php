<?php

namespace Templite\Cms\Services;

use Illuminate\Support\Collection;
use Templite\Cms\Models\File;
use Templite\Cms\Models\Page;
use Templite\Cms\Models\User;
use Templite\Cms\Models\UserField;
use Templite\Cms\Support\FieldValueCaster;

/**
 * Резолвер кастомных полей пользователей.
 *
 * По аналогии с BlockDataResolver: заменяет file_id -> File,
 * page_id -> Page, нормализует link, рекурсивно обрабатывает array.
 * Поддерживает batch-загрузку для списков пользователей.
 */
class UserDataResolver
{
    /**
     * Резолвить data одного пользователя.
     *
     * @return array Резолвленные данные с объектами вместо ID
     */
    public function resolve(User $user): array
    {
        $user->loadMissing('userType.rootFields.children');

        $fields = $user->userType->rootFields ?? collect();
        $data = $user->data ?? [];

        if ($fields->isEmpty() || empty($data)) {
            return $data;
        }

        // Собираем ID
        $fileIds = [];
        $pageIds = [];
        $this->collectIds($fields, $data, $fileIds, $pageIds);

        // Загружаем связанные сущности
        $files = !empty($fileIds)
            ? File::whereIn('id', array_unique($fileIds))->get()->keyBy('id')
            : collect();

        $pages = !empty($pageIds)
            ? Page::whereIn('id', array_unique($pageIds))->get()->keyBy('id')
            : collect();

        return $this->resolveData($fields, $data, $files, $pages);
    }

    /**
     * Batch-резолвинг для коллекции пользователей.
     *
     * Eager-загружает userType.rootFields.children, собирает все ID
     * из всех пользователей, делает единый запрос File/Page, затем
     * подставляет объекты.
     */
    public function resolveMany(Collection $users): void
    {
        if ($users->isEmpty()) {
            return;
        }

        // Eager load
        $users->loadMissing('userType.rootFields.children');

        // Собираем все ID из всех пользователей
        $fileIds = [];
        $pageIds = [];

        foreach ($users as $user) {
            $fields = $user->userType->rootFields ?? collect();
            $data = $user->data ?? [];

            if ($fields->isNotEmpty() && !empty($data)) {
                $this->collectIds($fields, $data, $fileIds, $pageIds);
            }
        }

        // Batch-загрузка
        $files = !empty($fileIds)
            ? File::whereIn('id', array_unique($fileIds))->get()->keyBy('id')
            : collect();

        $pages = !empty($pageIds)
            ? Page::whereIn('id', array_unique($pageIds))->get()->keyBy('id')
            : collect();

        // Подставляем объекты
        foreach ($users as $user) {
            $fields = $user->userType->rootFields ?? collect();
            $data = $user->data ?? [];

            if ($fields->isNotEmpty() && !empty($data)) {
                $user->resolved_data = $this->resolveData($fields, $data, $files, $pages);
            } else {
                $user->resolved_data = $data;
            }
        }
    }

    /**
     * Собрать все file/page ID из данных для пакетной загрузки.
     */
    protected function collectIds(
        Collection $fields,
        array $data,
        array &$fileIds,
        array &$pageIds
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
            } elseif ($field->type === 'array' && is_array($value)) {
                $childFields = $field->children ?? collect();
                if ($childFields->isNotEmpty()) {
                    foreach ($value as $item) {
                        if (is_array($item)) {
                            $this->collectIds($childFields, $item, $fileIds, $pageIds);
                        }
                    }
                }
            }
        }
    }

    /**
     * Подставить объекты вместо ID и гарантировать типизированные значения.
     */
    protected function resolveData(
        Collection $fields,
        array $data,
        Collection $files,
        Collection $pages
    ): array {
        $resolved = $data;

        foreach ($fields as $field) {
            $key = $field->key;
            $value = $data[$key] ?? null;

            // Гарантируем типизированное значение по умолчанию
            if ($value === null || $value === '') {
                $resolved[$key] = $this->getDefaultForType($field);
                continue;
            }

            if ($field->type === 'img' || $field->type === 'file') {
                $resolved[$key] = is_numeric($value) ? ($files[(int) $value] ?? null) : null;
            } elseif ($field->type === 'page') {
                $resolved[$key] = is_numeric($value) ? ($pages[(int) $value] ?? null) : null;
            } elseif ($field->type === 'link') {
                if (is_string($value)) {
                    $resolved[$key] = ['url' => $value, 'text' => '', 'target' => '_self'];
                } elseif (is_array($value)) {
                    $resolved[$key] = array_merge(['url' => '', 'text' => '', 'target' => '_self'], $value);
                }
            } elseif ($field->type === 'array') {
                if (is_array($value)) {
                    $childFields = $field->children ?? collect();
                    if ($childFields->isNotEmpty()) {
                        $resolved[$key] = array_map(function ($item) use ($childFields, $files, $pages) {
                            if (is_array($item)) {
                                return $this->resolveData($childFields, $item, $files, $pages);
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
     * Получить значение по умолчанию для типа поля.
     */
    protected function getDefaultForType(UserField $field): mixed
    {
        // Если у поля задан default_value — используем с приведением типа
        if ($field->default_value !== null && $field->default_value !== '') {
            return FieldValueCaster::cast($field->default_value, $field->type);
        }

        return match ($field->type) {
            'array'            => [],
            'checkbox'         => false,
            'number'           => 0,
            'link'             => ['url' => '', 'text' => '', 'target' => '_self'],
            'img', 'file', 'page' => null,
            // text, textfield, editor, tiptap, html, color, date, datetime, select, radio
            default            => '',
        };
    }
}
