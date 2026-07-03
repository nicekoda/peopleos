<?php

namespace App\Http\Requests\Policy;

use App\Models\Tenant;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * employee_id is now optional (Checkpoint 11) — if omitted, the
 * controller resolves it to the caller's own linked employee (genuine
 * self-service, now that App\Models\User::employee() exists). If
 * explicitly provided and it doesn't match the caller's own link, the
 * controller treats it as an admin-recorded-on-behalf-of action, gated
 * by an additional permission check — see
 * PolicyController::acknowledge() and docs/security.md.
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
                'nullable', 'string',
                Rule::exists('employees', 'id')->where(fn ($q) => $q->where('tenant_id', $tenantId)),
            ],
        ];
    }
}
