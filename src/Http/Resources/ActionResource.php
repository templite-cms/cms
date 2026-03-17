<?php

namespace Templite\Cms\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ActionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'class_name' => $this->class_name,
            'source' => $this->source,
            'params' => $this->params,
            'returns' => $this->returns,
            'description' => $this->description,
            'allow_http' => (bool) $this->allow_http,
            'screen' => $this->when($this->relationLoaded('screenshot'), fn() => new FileResource($this->screenshot)),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
