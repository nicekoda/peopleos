<?php

namespace Database\Factories;

use App\Enums\LifecycleTaskStatus;
use App\Models\LifecycleProcess;
use App\Models\LifecycleTask;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LifecycleTask>
 */
class LifecycleTaskFactory extends Factory
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
            'process_id' => LifecycleProcess::factory(),
            'title' => fake()->sentence(3),
            'status' => LifecycleTaskStatus::Pending,
        ];
    }

    public function completed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => LifecycleTaskStatus::Completed,
            'completed_at' => now(),
        ]);
    }

    public function skipped(): static
    {
        return $this->state(fn (array $attributes) => ['status' => LifecycleTaskStatus::Skipped]);
    }
}
