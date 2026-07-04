<?php

namespace App\Http\Requests\User;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Status-only (Checkpoint 23, Refinement 3) — validates exactly one
 * field. name/email/password/tenant_id/is_platform_admin/
 * email_verified_at/last_login_at/last_login_ip/remember_token/roles/
 * permissions/employee-link fields are structurally absent from these
 * rules, not merely omitted from the frontend form — a request body
 * containing any of them has those keys silently dropped by
 * FormRequest::validated() before the controller ever sees them.
 */
class UpdateUserStatusRequest extends FormRequest
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
            'status' => ['required', Rule::in([
                User::STATUS_ACTIVE,
                User::STATUS_INACTIVE,
                User::STATUS_SUSPENDED,
            ])],
        ];
    }
}
