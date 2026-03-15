<?php

namespace Templite\Cms\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PageTypeAttributeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'page_type_id' => $this->page_type_id,
            'name' => $this->name,
            'key' => $this->key,
            'type' => $this->type,
            'options' => $this->options,
            'filterable' => $this->filterable,
            'sortable' => $this->sortable,
            'required' => $this->required,
            'order' => $this->order,
        ];
    }
}
