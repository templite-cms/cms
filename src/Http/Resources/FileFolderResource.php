<?php

namespace Templite\Cms\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class FileFolderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'parent_id' => $this->parent_id,
            'order' => $this->order,
            'children' => $this->when($this->relationLoaded('children'), fn() => FileFolderResource::collection($this->children)),
            'files_count' => $this->when(isset($this->files_count), $this->files_count),
            'children_count' => $this->when(isset($this->children_count), $this->children_count),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
