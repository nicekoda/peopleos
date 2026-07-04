<?php

namespace App\Http\Requests\Role;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Checkpoint 28 — permission_id must resolve to an existing,
 * tenant-safe (non-platform-only) permission. This is the first of two
 * layers: RolePermissionController independently re-checks the target
 * role's tenant/platform/system-role eligibility before calling
 * Role::assignPermission(), which itself re-asserts the platform-vs-
 * tenant scope match (Role::givePermissionTo()'s existing guard) —
 * so even if this validation rule were ever weakened, a platform-only
 * permission still cannot attach to a tenant role.
 */
class AssignRolePermissionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'permission_id' => [
                'required', 'integer',
                Rule::exists('permissions', 'id')->where(fn ($query) => $query
                    ->where('is_platform_permission', false)),
            ],
        ];
    }
}
