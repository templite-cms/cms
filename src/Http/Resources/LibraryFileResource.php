<?php

namespace Templite\Cms\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class LibraryFileResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'source' => $this->source,
            'path' => $this->path,
            'url' => $this->url,
            'original_name' => $this->original_name,
            'version' => $this->version,
            'loading' => $this->loading,
            'media' => $this->media,
            'sort_order' => $this->sort_order,
            'resolved_url' => $this->resolvedUrl(),
        ];
    }
}
