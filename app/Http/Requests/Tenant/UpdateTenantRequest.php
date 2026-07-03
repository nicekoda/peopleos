<?php

namespace App\Http\Requests\Tenant;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Deliberately validates only `name` (Checkpoint 22, Refinement 2).
 * subdomain/status/tenant_id/created_at/updated_at/deleted_at and any
 * future billing/security/system-flag field are structurally absent
 * from these rules — not merely omitted from the frontend form. A
 * request body containing any of those is simply ignored for those
 * keys (FormRequest::validated() only ever returns keys with a rule),
 * never applied to the model. See docs/security.md.
 */
class UpdateTenantRequest extends FormRequest
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
            'name' => ['sometimes', 'required', 'string', 'max:255'],
        ];
    }
}
