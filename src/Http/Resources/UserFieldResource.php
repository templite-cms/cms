<?php

namespace Templite\Cms\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserFieldResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'user_type_id' => $this->user_type_id,
            'parent_id' => $this->parent_id,
            'name' => $this->name,
            'key' => $this->key,
            'type' => $this->type,
            'default_value' => $this->default_value,
            'data' => $this->data,
            'hint' => $this->hint,
            'tab' => $this->tab,
            'order' => $this->order,
            'children' => $this->when(
                $this->relationLoaded('children'),
                fn() => self::collection($this->children)
            ),
        ];
    }
}
