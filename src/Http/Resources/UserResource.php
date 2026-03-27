<?php

namespace Templite\Cms\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'user_type_id' => $this->user_type_id,
            'name' => $this->name,
            'email' => $this->email,
            'email_verified_at' => $this->email_verified_at?->toIso8601String(),
            'avatar_id' => $this->avatar_id,
            'avatar_url' => $this->avatar_url,
            'avatar' => $this->when(
                $this->relationLoaded('avatar'),
                fn() => new FileResource($this->avatar)
            ),
            'data' => $this->data,
            'settings' => $this->settings,
            'is_active' => $this->is_active,
            'type' => $this->when(
                $this->relationLoaded('userType'),
                fn() => new UserTypeResource($this->userType)
            ),
            'resolved_data' => $this->when(
                isset($this->resolved_data),
                fn() => $this->resolved_data
            ),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
