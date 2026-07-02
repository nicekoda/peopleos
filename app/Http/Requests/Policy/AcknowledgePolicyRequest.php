<?php

namespace App\Http\Requests\Policy;

use App\Models\Tenant;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Admin/HR-recorded acknowledgement only this checkpoint — employee_id is
 * always explicit request input, never derived from the authenticated
 * session, since no verified user-to-employee link exists yet. See
 * docs/security.md for the full reasoning.
 */
class AcknowledgePolicyRequest extends FormRequest
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
            'employee_id' => [
                'required', 'string',
                Rule::exists('employees', 'id')->where(fn ($q) => $q->where('tenant_id', $tenantId)),
            ],
        ];
    }
}
