<?php

namespace Database\Factories;

use App\Enums\AcknowledgementStatus;
use App\Models\Employee;
use App\Models\Policy;
use App\Models\PolicyAcknowledgement;
use App\Models\PolicyVersion;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PolicyAcknowledgement>
 */
class PolicyAcknowledgementFactory extends Factory
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
            'policy_id' => Policy::factory(),
            'policy_version_id' => PolicyVersion::factory(),
            'employee_id' => Employee::factory(),
            'assigned_at' => now(),
            'acknowledgement_status' => AcknowledgementStatus::Pending,
        ];
    }

    public function acknowledged(): static
    {
        return $this->state(fn (array $attributes) => [
            'acknowledgement_status' => AcknowledgementStatus::Acknowledged,
            'acknowledged_at' => now(),
        ]);
    }
}
