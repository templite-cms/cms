<?php

namespace Templite\Cms\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CityResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'name_genitive' => $this->name_genitive,
            'name_prepositional' => $this->name_prepositional,
            'name_accusative' => $this->name_accusative,
            'slug' => $this->slug,
            'region' => $this->region,
            'phone' => $this->phone,
            'address' => $this->address,
            'email' => $this->email,
            'coordinates' => $this->coordinates,
            'extra_data' => $this->extra_data,
            'is_default' => $this->is_default,
            'is_active' => $this->is_active,
            'sort_order' => $this->sort_order,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
