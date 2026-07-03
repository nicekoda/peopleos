<?php

namespace App\Http\Requests\LeaveBalance;

use App\Enums\LeaveTypeStatus;
use App\Models\Tenant;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreLeaveBalanceRequest extends FormRequest
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
                Rule::exists('employees', 'id')->where(fn ($q) => $q
                    ->where('tenant_id', $tenantId)
                    ->whereNull('deleted_at')),
            ],
            // Rule::exists() bypasses SoftDeletes — status/deleted_at
            // must be checked explicitly, the same fix required since
            // Checkpoint 9 (document categories) and Checkpoint 12
            // (leave types themselves).
            'leave_type_id' => [
                'required', 'string',
                Rule::exists('leave_types', 'id')->where(fn ($q) => $q
                    ->where('tenant_id', $tenantId)
                    ->where('status', LeaveTypeStatus::Active->value)
                    ->whereNull('deleted_at')),
            ],
            'year' => [
                'required', 'integer', 'min:2000', 'max:2100',
                // Duplicate (tenant, employee, leave type, year) rejected
                // here with a clean 422 — the partial unique index at the
                // DB layer (see the migration) is the backstop, not the
                // only check. whereNull('deleted_at') so recreating a
                // balance after a soft-deleted one isn't blocked.
                Rule::unique('leave_balances', 'year')->where(fn ($q) => $q
                    ->where('tenant_id', $tenantId)
                    ->where('employee_id', $this->input('employee_id'))
                    ->where('leave_type_id', $this->input('leave_type_id'))
                    ->whereNull('deleted_at')),
            ],
            'entitlement_days' => ['required', 'numeric', 'min:0', 'max:999.99'],
            'carried_forward_days' => ['nullable', 'numeric', 'min:0', 'max:999.99'],
            'adjustment_days' => ['nullable', 'numeric', 'min:-999.99', 'max:999.99'],
        ];
    }
}
