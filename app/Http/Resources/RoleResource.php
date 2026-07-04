<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Deliberately narrow (Checkpoint 23, Refinement 7; extended Checkpoint
 * 28) — no raw role_permission pivot rows, no guard/internal
 * implementation details, no tenant_id/created_by/updated_by/deleted_at.
 * `permission_count`/`user_count` are computed integers
 * (`withCount()` in the controller); the actual `permissions` array is
 * only ever populated when the controller has eager-loaded the
 * relationship (`show()`/`store()`/permission assignment responses) —
 * `index()` never loads it, so the list endpoint stays exactly as
 * narrow as before this checkpoint.
 *
 * @return array<string, mixed>
 */
class RoleResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'description' => $this->description,
            'is_platform_role' => $this->is_platform_role,
            'is_system_role' => $this->is_system_role,
            'permission_count' => $this->permissions_count ?? $this->permissions()->count(),
            'user_count' => $this->users_count ?? null,
            'permissions' => $this->whenLoaded('permissions', fn () => PermissionResource::collection($this->permissions)),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
