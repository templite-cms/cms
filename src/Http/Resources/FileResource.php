<?php

namespace Templite\Cms\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class FileResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $url = $this->disk === 'local'
            ? '/api/cms/media/serve/' . $this->id
            : $this->url;

        $meta = $this->meta ?? [];

        // Подготовить информацию о размерах с URL и размером файла
        $sizesInfo = null;
        if (!empty($this->sizes) && is_array($this->sizes)) {
            $sizesInfo = [];
            foreach ($this->sizes as $sizeName => $sizeData) {
                $entry = [
                    'name' => $sizeName,
                    'width' => $sizeData['width'] ?? null,
                    'height' => $sizeData['height'] ?? null,
                    'formats' => [],
                ];
                foreach ($sizeData as $format => $path) {
                    if (in_array($format, ['width', 'height'])) continue;
                    $fileSize = null;
                    try {
                        $fileSize = \Illuminate\Support\Facades\Storage::disk($this->disk)->size($path);
                    } catch (\Throwable) {}
                    $entry['formats'][$format] = [
                        'path' => $path,
                        'url' => $this->disk === 'local'
                            ? '/api/cms/media/serve/' . $this->id . '?size=' . $sizeName . '&format=' . $format
                            : \Illuminate\Support\Facades\Storage::disk($this->disk)->url($path),
                        'file_size' => $fileSize,
                    ];
                }
                $sizesInfo[] = $entry;
            }
        }

        return [
            'id' => $this->id,
            'name' => $this->name,
            'path' => $this->path,
            'disk' => $this->disk,
            'url' => $url,
            'size' => $this->size,
            'mime' => $this->mime,
            'type' => $this->type,
            'alt' => $this->alt,
            'title' => $this->title,
            'width' => $meta['width'] ?? null,
            'height' => $meta['height'] ?? null,
            'sizes' => $this->sizes,
            'sizes_info' => $sizesInfo,
            'meta' => $meta,
            'folder_id' => $this->folder_id,
            'folder_name' => $this->whenLoaded('folder', fn() => $this->folder?->name),
            'folder_path' => $this->whenLoaded('folder', fn() => $this->folder?->getPathNames() ?? []),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
