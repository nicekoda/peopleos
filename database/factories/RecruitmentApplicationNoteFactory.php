<?php

namespace Database\Factories;

use App\Models\RecruitmentApplication;
use App\Models\RecruitmentApplicationNote;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RecruitmentApplicationNote>
 */
class RecruitmentApplicationNoteFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'recruitment_application_id' => RecruitmentApplication::factory(),
            'note' => $this->faker->sentence(),
            'visibility' => 'internal',
        ];
    }
}
