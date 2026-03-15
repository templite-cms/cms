<?php

namespace Templite\Cms\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BlockTabResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'block_id' => $this->block_id,
            'order' => $this->order,
            'columns' => $this->columns ?? 1,
            'column_widths' => $this->column_widths,
            'sections' => $this->when($this->relationLoaded('sections'), fn() => BlockSectionResource::collection($this->sections)),
        ];
    }
}
