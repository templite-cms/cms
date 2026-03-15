<?php

namespace Templite\Cms\Services;

use Illuminate\Database\Eloquent\Model;
use Templite\Cms\Models\BlockField;
use Templite\Cms\Models\BlockSection;
use Templite\Cms\Models\BlockTab;

class FieldableService
{
    /**
     * Get fields tree (root fields with children).
     */
    public function getFieldsTree(Model $fieldable): \Illuminate\Database\Eloquent\Collection
    {
        return $fieldable->rootFieldDefinitions()
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

        // Backward compatibility: if fieldable is Block, also set block_id
        if ($fieldable instanceof \Templite\Cms\Models\Block) {
            $data['block_id'] = $fieldable->getKey();
        }

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
            $updateData = ['order' => $item['order']];

            if (array_key_exists('block_section_id', $item)) {
                $updateData['block_section_id'] = $item['block_section_id'];
            }
            if (array_key_exists('block_tab_id', $item)) {
                $updateData['block_tab_id'] = $item['block_tab_id'];
            }

            BlockField::where('id', $item['id'])
                ->where('fieldable_type', $fieldable->getMorphClass())
                ->where('fieldable_id', $fieldable->getKey())
                ->update($updateData);
        }
    }

    /**
     * Create a tab for the fieldable entity.
     */
    public function createTab(Model $fieldable, array $data): BlockTab
    {
        $data['fieldable_type'] = $fieldable->getMorphClass();
        $data['fieldable_id'] = $fieldable->getKey();

        if ($fieldable instanceof \Templite\Cms\Models\Block) {
            $data['block_id'] = $fieldable->getKey();
        }

        if (!isset($data['order'])) {
            $data['order'] = BlockTab::where('fieldable_type', $fieldable->getMorphClass())
                ->where('fieldable_id', $fieldable->getKey())
                ->max('order') + 1;
        }

        return BlockTab::create($data);
    }

    /**
     * Reorder tabs for the fieldable entity.
     */
    public function reorderTabs(Model $fieldable, array $ids): void
    {
        foreach ($ids as $order => $tabId) {
            BlockTab::where('id', $tabId)
                ->where('fieldable_type', $fieldable->getMorphClass())
                ->where('fieldable_id', $fieldable->getKey())
                ->update(['order' => $order]);
        }
    }

    /**
     * Create a section for the fieldable entity.
     */
    public function createSection(Model $fieldable, array $data): BlockSection
    {
        $data['fieldable_type'] = $fieldable->getMorphClass();
        $data['fieldable_id'] = $fieldable->getKey();

        if ($fieldable instanceof \Templite\Cms\Models\Block) {
            $data['block_id'] = $fieldable->getKey();
        }

        if (!isset($data['order'])) {
            $data['order'] = BlockSection::where('fieldable_type', $fieldable->getMorphClass())
                ->where('fieldable_id', $fieldable->getKey())
                ->max('order') + 1;
        }

        return BlockSection::create($data);
    }

    /**
     * Reorder sections for the fieldable entity.
     */
    public function reorderSections(Model $fieldable, array $ids): void
    {
        foreach ($ids as $order => $sectionId) {
            BlockSection::where('id', $sectionId)
                ->where('fieldable_type', $fieldable->getMorphClass())
                ->where('fieldable_id', $fieldable->getKey())
                ->update(['order' => $order]);
        }
    }
}
