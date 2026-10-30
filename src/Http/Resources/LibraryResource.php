<?php

namespace Templite\Cms\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class LibraryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'description' => $this->description,
            'sort_order' => $this->sort_order,
            'active' => $this->active,
            'files' => LibraryFileResource::collection($this->whenLoaded('files')),
            'files_count' => $this->when(isset($this->files_count), $this->files_count),
            'blocks_count' => $this->when(isset($this->blocks_count), $this->blocks_count),
            'template_pages_count' => $this->when(isset($this->template_pages_count), $this->template_pages_count),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
