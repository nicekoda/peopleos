<?php

namespace App\Http\Requests\Leave;

use App\Enums\LeaveTypeStatus;
use App\Models\Tenant;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Deliberately has no employee_id field. This checkpoint's leave request
 * creation is self-service only — the controller always resolves the
 * employee from the caller's own verified link
 * ($request->user()->employee). There is no permission in this
 * checkpoint's catalog that would let a caller create a leave request on
 * behalf of someone else (unlike policies.assign for policy
 * acknowledgement) — see docs/security.md.
 */
class StoreLeaveRequestRequest extends FormRequest
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
            // Rule::exists() is a raw DB check that bypasses Eloquent's
            // SoftDeletes scope — status/deleted_at must be checked
            // explicitly here, the same fix already applied to
            // StoreEmployeeDocumentRequest's document_category_id in
            // Checkpoint 9.
            'leave_type_id' => [
                'required', 'string',
                Rule::exists('leave_types', 'id')->where(fn ($q) => $q
                    ->where('tenant_id', $tenantId)
                    ->where('status', LeaveTypeStatus::Active->value)
                    ->whereNull('deleted_at')),
            ],
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after_or_equal:start_date'],
            'reason' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
