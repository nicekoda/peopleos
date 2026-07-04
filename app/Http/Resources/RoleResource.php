<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Deliberately narrow (Checkpoint 23, Refinement 7) — no raw
 * role_permission pivot rows, no guard/internal implementation
 * details. `permission_count` is a computed integer
 * (`withCount('permissions')` in the controller), never the actual
 * permission list — a role's exact permission set isn't shown in this
 * checkpoint's read-only role list, only how many it holds.
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
            'permission_count' => $this->permissions_count ?? $this->permissions()->count(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
