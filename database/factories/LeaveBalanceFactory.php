<?php

namespace Database\Factories;

use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\LeaveType;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LeaveBalance>
 */
class LeaveBalanceFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'employee_id' => Employee::factory(),
            'leave_type_id' => LeaveType::factory(),
            'year' => (int) now()->year,
            'entitlement_days' => 20,
            'used_days' => 0,
            'pending_days' => 0,
            'carried_forward_days' => 0,
            'adjustment_days' => 0,
        ];
    }
}
