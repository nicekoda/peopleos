<?php

namespace Database\Factories;

use App\Enums\PolicyStatus;
use App\Models\Policy;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Policy>
 */
class PolicyFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $title = ucfirst(fake()->unique()->words(3, true)).' Policy';

        return [
            'tenant_id' => Tenant::factory(),
            'title' => $title,
            'slug' => Str::slug($title),
            'status' => PolicyStatus::Draft,
        ];
    }

    public function published(): static
    {
        return $this->state(fn (array $attributes) => ['status' => PolicyStatus::Published]);
    }
}
