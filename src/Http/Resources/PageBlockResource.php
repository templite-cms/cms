<?php

namespace Templite\Cms\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PageBlockResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'page_id' => $this->page_id,
            'block_id' => $this->block_id,
            'data' => $this->data,
            'action_params' => $this->action_params,
            'order' => $this->order,
            'cache_enabled' => $this->cache_enabled,
            'cache_key' => $this->cache_key,
            'status' => $this->status?->value ?? 'published',
            'active_version_id' => $this->page_block_data_id,
            'preset_id' => $this->preset_id,
            'field_overrides' => $this->field_overrides,
            'preset' => $this->when(
                $this->relationLoaded('preset'),
                fn() => $this->preset ? [
                    'id' => $this->preset->id,
                    'name' => $this->preset->name,
                    'type' => $this->preset->type,
                    'data' => $this->preset->data,
                ] : null
            ),
            'block' => $this->when($this->relationLoaded('block'), fn() => new BlockResource($this->block)),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
