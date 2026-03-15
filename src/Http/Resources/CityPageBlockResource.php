<?php

namespace Templite\Cms\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CityPageBlockResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'city_page_id' => $this->city_page_id,
            'page_block_id' => $this->page_block_id,
            'block_id' => $this->block_id,
            'action' => $this->action,
            'data_override' => $this->data_override,
            'order_override' => $this->order_override,
            'block' => $this->when($this->relationLoaded('block'), fn() => new BlockResource($this->block)),
        ];
    }
}
