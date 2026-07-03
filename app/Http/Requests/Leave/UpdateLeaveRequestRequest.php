<?php

namespace App\Http\Requests\Leave;

use App\Enums\LeaveTypeStatus;
use App\Models\LeaveRequest;
use App\Models\Tenant;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

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

    /**
     * Cross-year leave requests are rejected for now (Checkpoint 15,
     * Option A) — same rule as StoreLeaveRequestRequest, falling back to
     * the route-bound request's existing dates for whichever of
     * start_date/end_date isn't present in this partial update.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            /** @var LeaveRequest $leaveRequest */
            $leaveRequest = $this->route('leaveRequest');

            $startDate = $this->input('start_date') ?? $leaveRequest->start_date?->toDateString();
            $endDate = $this->input('end_date') ?? $leaveRequest->end_date?->toDateString();

            if ($startDate && $endDate && date('Y', strtotime($startDate)) !== date('Y', strtotime($endDate))) {
                $validator->errors()->add('end_date', 'Leave requests cannot span more than one calendar year yet.');
            }
        });
    }
}
