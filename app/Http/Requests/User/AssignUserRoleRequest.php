<?php

namespace App\Http\Requests\User;

use App\Models\Tenant;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Tenant- and scope-restricted at the validation layer (Checkpoint 23,
 * Refinement 5) — role_id must resolve to a tenant role (not a platform
 * role) belonging to the current tenant. This is the first of two
 * layers: User::assignRole() independently re-checks platform-vs-tenant
 * scope and tenant ownership again before writing anything, so even if
 * this validation rule were ever weakened, the model method's own
 * RuntimeException guard remains a backstop.
 */
class AssignUserRoleRequest extends FormRequest
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
        $tenantId = app(Tenant::class)->id;

        return [
            'role_id' => [
                'required', 'integer',
                Rule::exists('roles', 'id')->where(fn ($query) => $query
                    ->where('tenant_id', $tenantId)
                    ->where('is_platform_role', false)
                    ->whereNull('deleted_at')),
            ],
        ];
    }
}
