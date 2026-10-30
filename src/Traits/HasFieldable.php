<?php

namespace Templite\Cms\Traits;

use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Collection;
use Templite\Cms\Models\BlockField;

/**
 * Владелец полей (Block, TemplatePage).
 *
 * Поля — плоский упорядоченный список; вложенность только у повторителей (parent_id).
 * Владелец хранится полиморфно — fieldable_type/fieldable_id.
 */
trait HasFieldable
{
    /**
     * Внешнего ключа у полиморфной связи нет — удаляем поля вместе с владельцем.
     */
    public static function bootHasFieldable(): void
    {
        static::deleting(fn ($owner) => $owner->deleteFields());
    }

    /**
     * Удалить все поля владельца.
     *
     * Без ORDER BY: MySQL выполняет DELETE с сортировкой по заранее собранному
     * списку строк и молча останавливается, когда каскад по parent_id уже удалил
     * следующую строку, — часть полей оставалась в таблице.
     */
    public function deleteFields(): void
    {
        $this->fields()->reorder()->delete();
    }

    /**
     * Все поля, включая вложенные в повторители.
     */
    public function fields(): MorphMany
    {
        return $this->morphMany(BlockField::class, 'fieldable')->orderBy('order');
    }

    public function rootFields(): MorphMany
    {
        return $this->morphMany(BlockField::class, 'fieldable')->whereNull('parent_id')->orderBy('order');
    }

    /**
     * Дерево полей для экспорта: корневые поля с вложенными children.
     */
    public function exportFields(): array
    {
        return $this->exportFieldList(BlockField::tree($this->fields()->get()));
    }

    /**
     * Заменить поля владельца деревом из экспорта.
     */
    public function importFields(array $fields): void
    {
        $this->deleteFields();
        $this->createFieldTree($fields, null);
    }

    private function exportFieldList(Collection $fields): array
    {
        return $fields->sortBy('order')->map(function (BlockField $f) {
            $arr = [
                'name' => $f->name,
                'key' => $f->key,
                'type' => $f->type,
                'data' => $f->data,
                'hint' => $f->hint,
                'order' => $f->order,
            ];
            if ($f->children->isNotEmpty()) {
                $arr['children'] = $this->exportFieldList($f->children);
            }
            return $arr;
        })->values()->toArray();
    }

    private function createFieldTree(array $fields, ?int $parentId): void
    {
        foreach ($fields as $fData) {
            $field = $this->fields()->create([
                'name' => $fData['name'],
                'key' => $fData['key'],
                'type' => $fData['type'],
                'data' => $fData['data'] ?? null,
                'hint' => $fData['hint'] ?? null,
                'order' => $fData['order'] ?? 0,
                'parent_id' => $parentId,
            ]);

            if (!empty($fData['children'])) {
                $this->createFieldTree($fData['children'], $field->id);
            }
        }
    }
}
