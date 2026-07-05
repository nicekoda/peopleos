<?php

namespace Database\Factories;

use App\Enums\LifecycleProcessStatus;
use App\Enums\LifecycleProcessType;
use App\Models\Employee;
use App\Models\LifecycleProcess;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LifecycleProcess>
 */
class LifecycleProcessFactory extends Factory
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
            'type' => LifecycleProcessType::Onboarding,
            'status' => LifecycleProcessStatus::Draft,
        ];
    }

    public function onboarding(): static
    {
        return $this->state(fn (array $attributes) => ['type' => LifecycleProcessType::Onboarding]);
    }

    public function offboarding(): static
    {
        return $this->state(fn (array $attributes) => ['type' => LifecycleProcessType::Offboarding]);
    }

    public function inProgress(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => LifecycleProcessStatus::InProgress,
            'started_at' => now(),
        ]);
    }

    public function completed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => LifecycleProcessStatus::Completed,
            'started_at' => now(),
            'completed_at' => now(),
        ]);
    }

    public function cancelled(): static
    {
        return $this->state(fn (array $attributes) => ['status' => LifecycleProcessStatus::Cancelled]);
    }
}
