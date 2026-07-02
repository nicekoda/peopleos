<?php

namespace Database\Factories;

use App\Models\Permission;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Permission>
 */
class PermissionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $key = 'test.'.fake()->unique()->word();

        return [
            'key' => $key,
            'category' => 'test',
            'is_platform_permission' => false,
            'description' => null,
        ];
    }

    public function platform(): static
    {
        return $this->state(fn (array $attributes) => [
            'key' => 'platform.test.'.fake()->unique()->word(),
            'category' => 'platform',
            'is_platform_permission' => true,
        ]);
    }
}
