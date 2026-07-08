<?php

namespace Database\Factories;

use App\Enums\ApplicationStage;
use App\Enums\ApplicationStatus;
use App\Models\RecruitmentApplicant;
use App\Models\RecruitmentApplication;
use App\Models\RecruitmentJob;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RecruitmentApplication>
 */
class RecruitmentApplicationFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'recruitment_job_id' => RecruitmentJob::factory(),
            'recruitment_applicant_id' => RecruitmentApplicant::factory(),
            'stage' => ApplicationStage::Applied,
            'status' => ApplicationStatus::Active,
            'ready_for_conversion' => false,
        ];
    }

    public function atStage(ApplicationStage $stage): static
    {
        return $this->state(fn (array $attributes) => ['stage' => $stage]);
    }
}
