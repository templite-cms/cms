<?php

namespace Templite\Cms\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PageBlockDataResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'page_block_id' => $this->page_block_id,
            'block_id' => $this->block_id,
            'block' => $this->when($this->relationLoaded('block') && $this->block, fn() => [
                'id' => $this->block->id,
                'name' => $this->block->name,
                'slug' => $this->block->slug,
                'screenshot_url' => $this->block->relationLoaded('screenshot') ? $this->block->screenshot?->url() : null,
                'block_type' => $this->when($this->block->relationLoaded('blockType') && $this->block->blockType, fn() => [
                    'id' => $this->block->blockType->id,
                    'name' => $this->block->blockType->name,
                ]),
            ]),
            'data' => $this->data,
            'action_params' => $this->action_params,
            'user' => $this->when($this->relationLoaded('user') && $this->user, fn() => [
                'id' => $this->user->id,
                'name' => $this->user->name,
                'login' => $this->user->login,
            ]),
            'change_type' => $this->change_type,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
