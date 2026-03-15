<?php

namespace Templite\Cms\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BlockActionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'block_id' => $this->block_id,
            'action_id' => $this->action_id,
            'params' => $this->params,
            'order' => $this->order,
            'action' => $this->when($this->relationLoaded('action'), fn() => new ActionResource($this->action)),
        ];
    }
}
