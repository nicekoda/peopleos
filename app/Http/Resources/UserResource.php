<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Deliberately narrow (Checkpoint 23, Refinement 6) — no password,
 * remember_token, tokens, MFA secrets, last_login_ip, raw provider IDs,
 * or raw role-pivot records. `roles` is a small safe summary
 * (id/name/slug only, never the pivot row itself); `linked_employee` is
 * `null` or `{id, full_name}` — never the full employee record. This
 * Resource only ever wraps tenant users already filtered by the
 * controller (see UserController); it never runs against a Platform
 * Super Admin record.
 *
 * @return array<string, mixed>
 */
class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'status' => $this->status,
            'is_platform_admin' => $this->is_platform_admin,
            'roles' => $this->roles->map(fn ($role) => [
                'id' => $role->id,
                'name' => $role->name,
                'slug' => $role->slug,
            ])->all(),
            'linked_employee' => $this->employee ? [
                'id' => $this->employee->id,
                'full_name' => $this->employee->fullName(),
            ] : null,
            'last_login_at' => $this->last_login_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
