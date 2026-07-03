<?php

namespace App\Http\Requests\LeaveBalance;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Refinement 5 — structurally excludes used_days, pending_days,
 * employee_id, leave_type_id, year, and tenant_id entirely. Only
 * entitlement_days/carried_forward_days/adjustment_days are even
 * validated fields — a request body containing any of the excluded
 * fields simply has them ignored, the same "not a validated field"
 * pattern used for tenant_id/manager_employee_id/etc. elsewhere.
 * adjustment_days additionally requires leave_balances.adjust, checked
 * in the controller (route middleware can't inspect a request body
 * value) — see LeaveBalanceController::update().
 */
class UpdateLeaveBalanceRequest extends FormRequest
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
            'entitlement_days' => ['sometimes', 'required', 'numeric', 'min:0', 'max:999.99'],
            'carried_forward_days' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:999.99'],
            'adjustment_days' => ['sometimes', 'nullable', 'numeric', 'min:-999.99', 'max:999.99'],
        ];
    }
}
