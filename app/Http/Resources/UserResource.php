<?php

namespace App\Http\Resources;

use App\Models\User;
use App\Support\PermissionCatalog;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin User */
class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'is_active' => $this->is_active,
            'last_login_at' => $this->last_login_at?->toIso8601String(),
            'roles' => $this->whenLoaded('roles', fn () => $this->roles->pluck('name')),
            'permissions' => $this->when($request->routeIs('auth.*'), fn () => $this->hasRole(PermissionCatalog::SUPER_ADMIN)
                ? PermissionCatalog::PERMISSIONS
                : $this->getAllPermissions()->pluck('name')->values()),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
