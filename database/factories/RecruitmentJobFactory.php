<?php

namespace Database\Factories;

use App\Enums\RecruitmentJobStatus;
use App\Models\RecruitmentJob;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RecruitmentJob>
 */
class RecruitmentJobFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'title' => $this->faker->jobTitle(),
            'status' => RecruitmentJobStatus::Draft,
        ];
    }

    public function open(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => RecruitmentJobStatus::Open,
            'opened_at' => now(),
        ]);
    }

    public function closed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => RecruitmentJobStatus::Closed,
            'opened_at' => now()->subWeek(),
            'closed_at' => now(),
        ]);
    }
}
