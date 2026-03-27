<?php

namespace Templite\Cms\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PageTypeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'icon' => $this->icon,
            'template_page_id' => $this->template_page_id,
            'template_name' => $this->when($this->relationLoaded('templatePage'), fn() => $this->templatePage?->name),
            'settings' => $this->settings,
            'attributes' => $this->when($this->relationLoaded('attributes'), fn() => PageTypeAttributeResource::collection($this->attributes)),
            'attributes_count' => $this->when($this->relationLoaded('attributes'), fn() => $this->attributes->count()),
            'pages_count' => $this->when(isset($this->pages_count), $this->pages_count),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
