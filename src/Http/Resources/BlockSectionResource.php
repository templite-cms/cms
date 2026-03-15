<?php

namespace Templite\Cms\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BlockSectionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'block_id' => $this->block_id,
            'block_tab_id' => $this->block_tab_id,
            'order' => $this->order,
            'column_index' => $this->column_index ?? 0,
            'fields' => $this->when(
                $this->relationLoaded('fields'),
                fn() => BlockFieldResource::collection($this->fields)
            ),
        ];
    }
}
