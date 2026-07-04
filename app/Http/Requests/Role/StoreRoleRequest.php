<?php

namespace App\Http\Requests\Role;

use App\Models\Tenant;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Checkpoint 28 — name/description only. tenant_id, is_system_role,
 * is_platform_role, slug, and every other internal/system flag are
 * structurally absent from these rules, not merely omitted from the
 * frontend form: a request body containing any of them has those keys
 * silently dropped by FormRequest::validated() before the controller
 * ever sees them. The controller sets tenant_id/is_system_role(false)/
 * is_platform_role(false) explicitly from trusted context, and derives
 * slug from name server-side — never from request input.
 */
class StoreRoleRequest extends FormRequest
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
            'name' => [
                'required', 'string', 'max:255',
                Rule::unique('roles', 'name')->where(fn ($query) => $query
                    ->where('tenant_id', $tenantId)
                    ->whereNull('deleted_at')),
            ],
            'description' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
