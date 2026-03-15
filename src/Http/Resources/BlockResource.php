<?php

namespace Templite\Cms\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Templite\Cms\Http\Resources\LibraryResource;

/**
 * BF-017: BlockResource -- включение fields/tabs/sections деревом.
 *
 * При загрузке блока для редактирования (GET /api/cms/blocks/{id})
 * ответ включает fields как дерево (top-level с вложенными children),
 * а также tabs и sections.
 */
class BlockResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'description' => $this->description,
            'block_type_id' => $this->block_type_id,
            'source' => $this->source,
            'path' => $this->path,
            'controller' => $this->controller,
            'tags' => $this->tags,
            'screen' => $this->when($this->relationLoaded('screenshot'), fn() => new FileResource($this->screenshot)),
            'order' => $this->order,
            'no_wrapper' => (bool) $this->no_wrapper,
            'type' => $this->when($this->relationLoaded('blockType'), fn() => new BlockTypeResource($this->blockType)),

            // BF-017: Поля как дерево -- возвращаем rootFields с children,
            // либо все fields если загружена связь rootFields
            'fields' => $this->when(
                $this->relationLoaded('rootFields') || $this->relationLoaded('fields'),
                function () {
                    if ($this->relationLoaded('rootFields')) {
                        return BlockFieldResource::collection($this->rootFields);
                    }
                    // Если загружены все fields, отфильтровать только top-level
                    $fields = $this->fields->whereNull('parent_id')->sortBy('order')->values();
                    return BlockFieldResource::collection($fields);
                }
            ),

            'tabs' => $this->when(
                $this->relationLoaded('tabs'),
                fn() => BlockTabResource::collection($this->tabs)
            ),
            'sections' => $this->when(
                $this->relationLoaded('sections'),
                fn() => BlockSectionResource::collection($this->sections)
            ),
            'actions' => $this->when(
                $this->relationLoaded('blockActions'),
                fn() => BlockActionResource::collection($this->blockActions)
            ),
            'libraries' => $this->when(
                $this->relationLoaded('libraries'),
                fn() => LibraryResource::collection($this->libraries)
            ),

            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
