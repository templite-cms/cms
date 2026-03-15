<?php

namespace Templite\Cms\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class GlobalFieldResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'parent_id' => $this->parent_id,
            'type' => $this->type,
            'key' => $this->key,
            'default_value' => $this->default_value,
            'data' => $this->data,
            'global_field_page_id' => $this->global_field_page_id,
            'global_field_section_id' => $this->global_field_section_id,
            'order' => $this->order,
            'values' => $this->when($this->relationLoaded('values'), fn() => GlobalFieldValueResource::collection($this->values)),
            'children' => $this->when($this->relationLoaded('children'), fn() => GlobalFieldResource::collection($this->children)),
        ];
    }
}
