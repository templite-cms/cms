<?php

namespace Templite\Cms\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ManagerResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'login' => $this->login,
            'email' => $this->email,
            'name' => $this->name,
            'type_id' => $this->type_id,
            'is_active' => $this->is_active,
            'settings' => $this->settings,
            'use_personal_permissions' => $this->use_personal_permissions,
            'personal_permissions' => $this->personal_permissions,
            'avatar_id' => $this->avatar_id,
            'avatar_url' => $this->avatar_url,
            'avatar' => $this->when($this->relationLoaded('avatar'), fn() => new FileResource($this->avatar)),
            'type' => $this->when($this->relationLoaded('managerType'), fn() => new ManagerTypeResource($this->managerType)),
            'permissions' => $this->when($request->routeIs('*.me'), fn() => $this->getPermissions()),
            'created_at' => $this->created_at?->toIso8601String(),
            'two_factor_enabled' => $this->hasTwoFactorEnabled(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
