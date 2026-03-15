<?php

namespace Templite\Cms\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CityPageResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'city_id' => $this->city_id,
            'source_page_id' => $this->source_page_id,
            'is_materialized' => $this->is_materialized,
            'materialized_page_id' => $this->materialized_page_id,
            'title_override' => $this->title_override,
            'bread_title_override' => $this->bread_title_override,
            'seo_data_override' => $this->seo_data_override,
            'social_data_override' => $this->social_data_override,
            'template_data_override' => $this->template_data_override,
            'status_override' => $this->status_override,
            'city' => $this->when($this->relationLoaded('city'), fn() => new CityResource($this->city)),
            'source_page' => $this->when($this->relationLoaded('sourcePage'), fn() => new PageResource($this->sourcePage)),
            'block_overrides' => $this->when($this->relationLoaded('blockOverrides'), fn() => CityPageBlockResource::collection($this->blockOverrides)),
            'has_overrides' => $this->hasOverrides(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
