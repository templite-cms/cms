<?php

namespace Templite\Cms\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * BF-016: BlockFieldResource -- полная структура с children.
 *
 * Возвращает поле блока с вложенными children (рекурсивно).
 * Если children не загружены (не eager-loaded), возвращается пустой массив.
 */
class BlockFieldResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'key' => $this->key,
            'type' => $this->type,
            'data' => $this->data,
            'hint' => $this->hint ?? ($this->data['hint'] ?? null),
            'parent_id' => $this->parent_id,
            'order' => $this->order,
            'children' => $this->relationLoaded('children')
                ? BlockFieldResource::collection($this->children)
                : [],
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
