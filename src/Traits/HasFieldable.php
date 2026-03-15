<?php

namespace Templite\Cms\Traits;

use Illuminate\Database\Eloquent\Relations\MorphMany;
use Templite\Cms\Models\BlockTab;
use Templite\Cms\Models\BlockSection;
use Templite\Cms\Models\BlockField;

trait HasFieldable
{
    public function fieldTabs(): MorphMany
    {
        return $this->morphMany(BlockTab::class, 'fieldable')->orderBy('order');
    }

    public function fieldSections(): MorphMany
    {
        return $this->morphMany(BlockSection::class, 'fieldable')->orderBy('order');
    }

    public function fieldDefinitions(): MorphMany
    {
        return $this->morphMany(BlockField::class, 'fieldable')->orderBy('order');
    }

    public function rootFieldDefinitions(): MorphMany
    {
        return $this->morphMany(BlockField::class, 'fieldable')->whereNull('parent_id')->orderBy('order');
    }
}
