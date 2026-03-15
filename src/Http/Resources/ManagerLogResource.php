<?php

namespace Templite\Cms\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ManagerLogResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'manager_id' => $this->manager_id,
            'action' => $this->action,
            'entity_type' => $this->entity_type,
            'entity_id' => $this->entity_id,
            'data' => $this->data,
            'ip' => $this->ip,
            'manager' => $this->when($this->relationLoaded('manager'), fn() => new ManagerResource($this->manager)),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
