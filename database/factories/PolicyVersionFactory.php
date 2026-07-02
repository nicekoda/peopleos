<?php

namespace Database\Factories;

use App\Enums\PolicyStatus;
use App\Models\Policy;
use App\Models\PolicyVersion;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PolicyVersion>
 */
class PolicyVersionFactory extends Factory
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
            'version_number' => 1,
            'title' => fake()->sentence(3),
            'content' => fake()->paragraphs(3, true),
            'status' => PolicyStatus::Draft,
        ];
    }

    public function published(): static
    {
        return $this->state(fn (array $attributes) => ['status' => PolicyStatus::Published]);
    }
}
