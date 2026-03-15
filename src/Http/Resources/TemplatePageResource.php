<?php

namespace Templite\Cms\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Templite\Cms\Http\Resources\LibraryResource;

class TemplatePageResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'settings' => $this->settings,
            'screen' => $this->when($this->relationLoaded('screenshot'), fn() => new FileResource($this->screenshot)),
            'libraries' => $this->when(
                $this->relationLoaded('libraries'),
                fn() => LibraryResource::collection($this->libraries)
            ),
            'tabs' => $this->when(
                $this->relationLoaded('tabs') || $this->relationLoaded('fieldTabs'),
                fn() => BlockTabResource::collection($this->relationLoaded('tabs') ? $this->tabs : $this->fieldTabs)
            ),
            'sections' => $this->when(
                $this->relationLoaded('sections') || $this->relationLoaded('fieldSections'),
                fn() => BlockSectionResource::collection($this->relationLoaded('sections') ? $this->sections : $this->fieldSections)
            ),
            'fields' => $this->when(
                $this->relationLoaded('rootFields') || $this->relationLoaded('rootFieldDefinitions'),
                fn() => BlockFieldResource::collection($this->relationLoaded('rootFields') ? $this->rootFields : $this->rootFieldDefinitions)
            ),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
