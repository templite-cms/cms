<?php

namespace Templite\Cms\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserTypeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'guard' => $this->guard,
            'module' => $this->module,
            'permissions' => $this->permissions,
            'settings' => $this->settings,
            'is_active' => $this->is_active,
            'users_count' => $this->when(isset($this->users_count), $this->users_count),
            'fields' => $this->when(
                $this->relationLoaded('rootFields'),
                fn() => UserFieldResource::collection($this->rootFields)
            ),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
