<?php

namespace Database\Factories;

use App\Enums\LifecycleProcessType;
use App\Models\LifecycleTaskTemplate;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LifecycleTaskTemplate>
 */
class LifecycleTaskTemplateFactory extends Factory
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
            'type' => LifecycleProcessType::Onboarding,
            'title' => ucfirst(fake()->unique()->words(3, true)),
            'description' => null,
            'due_in_days' => null,
            'sort_order' => 0,
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
}
