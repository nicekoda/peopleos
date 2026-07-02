<?php

namespace Database\Factories;

use App\Enums\DocumentAppliesTo;
use App\Enums\DocumentCategoryStatus;
use App\Models\DocumentCategory;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<DocumentCategory>
 */
class DocumentCategoryFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = ucfirst(fake()->unique()->words(2, true));

        return [
            'tenant_id' => Tenant::factory(),
            'name' => $name,
            'slug' => Str::slug($name),
            'applies_to' => DocumentAppliesTo::Employee,
            'is_sensitive' => false,
            'is_required' => false,
            'requires_expiry_date' => false,
            'status' => DocumentCategoryStatus::Active,
        ];
    }

    public function sensitive(): static
    {
        return $this->state(fn (array $attributes) => ['is_sensitive' => true]);
    }

    public function requiresExpiryDate(): static
    {
        return $this->state(fn (array $attributes) => ['requires_expiry_date' => true]);
    }
}
