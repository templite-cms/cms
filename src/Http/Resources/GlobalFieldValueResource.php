<?php

namespace Templite\Cms\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class GlobalFieldValueResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'global_field_id' => $this->global_field_id,
            'parent_id' => $this->parent_id,
            'value' => $this->value,
            'order' => $this->order,
            'children' => $this->when($this->relationLoaded('children'), fn() => GlobalFieldValueResource::collection($this->children)),
        ];
    }
}
