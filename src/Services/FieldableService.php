<?php

namespace Templite\Cms\Services;

use Illuminate\Database\Eloquent\Model;
use Templite\Cms\Models\BlockField;

class FieldableService
{
    /**
     * Get fields tree (root fields with children).
     */
    public function getFieldsTree(Model $fieldable): \Illuminate\Database\Eloquent\Collection
    {
        return $fieldable->rootFields()
            ->with([
                'children' => fn($q) => $q->orderBy('order'),
                'children.children' => fn($q) => $q->orderBy('order'),
            ])
            ->orderBy('order')
            ->get();
    }

    /**
     * Create a field for the fieldable entity.
     */
    public function createField(Model $fieldable, array $data): BlockField
    {
        $data['fieldable_type'] = $fieldable->getMorphClass();
        $data['fieldable_id'] = $fieldable->getKey();

        // Auto-order if not specified
        if (!isset($data['order'])) {
            $data['order'] = BlockField::where('fieldable_type', $fieldable->getMorphClass())
                ->where('fieldable_id', $fieldable->getKey())
                ->where('parent_id', $data['parent_id'] ?? null)
                ->max('order') + 1;
        }

        return BlockField::create($data);
    }

    /**
     * Reorder fields for the fieldable entity.
     */
    public function reorderFields(Model $fieldable, array $items): void
    {
        foreach ($items as $item) {
            BlockField::where('id', $item['id'])
                ->where('fieldable_type', $fieldable->getMorphClass())
                ->where('fieldable_id', $fieldable->getKey())
                ->update(['order' => $item['order']]);
        }
    }
}
