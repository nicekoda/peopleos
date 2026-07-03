<?php

namespace App\Http\Requests\Leave;

use App\Enums\LeaveTypeStatus;
use App\Models\Tenant;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * PATCH is deliberately narrow: only start_date/end_date/leave_type_id/
 * reason are validated fields at all. status, employee_id, tenant_id,
 * and every approval/rejection/cancellation field are structurally
 * absent from this rule set — not just "not expected," but not
 * accepted into $request->validated() no matter what a client sends,
 * the same "don't accept from request input" pattern used for
 * tenant_id/created_by everywhere else. Status changes only happen
 * through the dedicated submit/approve/reject/cancel actions.
 * Draft-only and owner-only are enforced in the controller (not
 * expressible as a field rule).
 */
class UpdateLeaveRequestRequest extends FormRequest
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
            'leave_type_id' => [
                'sometimes', 'required', 'string',
                Rule::exists('leave_types', 'id')->where(fn ($q) => $q
                    ->where('tenant_id', $tenantId)
                    ->where('status', LeaveTypeStatus::Active->value)
                    ->whereNull('deleted_at')),
            ],
            'start_date' => ['sometimes', 'required', 'date'],
            'end_date' => ['sometimes', 'required', 'date', 'after_or_equal:start_date'],
            'reason' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
