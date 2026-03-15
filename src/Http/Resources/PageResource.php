<?php

namespace Templite\Cms\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PageResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'url' => $this->url,
            'alias' => $this->alias,
            'parent_id' => $this->parent_id,
            'type_id' => $this->type_id,
            'title' => $this->title,
            'bread_title' => $this->bread_title,
            'seo_data' => $this->seo_data,
            'social_data' => $this->social_data,
            'template_page_id' => $this->template_page_id,
            'template_data' => $this->template_data,
            'status' => $this->status,
            'city_scope' => $this->city_scope,
            'city_id' => $this->city_id,
            'publish_at' => $this->publish_at?->toIso8601String(),
            'unpublish_at' => $this->unpublish_at?->toIso8601String(),
            'display_tree' => $this->display_tree,
            'views' => $this->views,
            'img' => $this->when($this->relationLoaded('image'), fn() => new FileResource($this->image)),
            'screen' => $this->when($this->relationLoaded('screenshot'), fn() => new FileResource($this->screenshot)),
            'order' => $this->order,
            'type' => $this->when($this->relationLoaded('pageType'), fn() => new PageTypeResource($this->pageType)),
            'template' => $this->when($this->relationLoaded('templatePage'), fn() => new TemplatePageResource($this->templatePage)),
            'parent' => $this->when($this->relationLoaded('parent'), fn() => $this->parent ? new PageResource($this->parent) : null),
            'children_count' => $this->when(isset($this->children_count), $this->children_count),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
