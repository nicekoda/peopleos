<?php

namespace Database\Factories;

use App\Models\Role;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Role>
 */
class RoleFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->jobTitle();

        return [
            'tenant_id' => Tenant::factory(),
            'is_platform_role' => false,
            'name' => $name,
            'slug' => Str::slug($name),
            'description' => null,
        ];
    }

    public function platform(): static
    {
        return $this->state(fn (array $attributes) => [
            'tenant_id' => null,
            'is_platform_role' => true,
        ]);
    }
}
