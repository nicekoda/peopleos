<?php

namespace Database\Factories;

use App\Enums\HrDocumentType;
use App\Enums\HrGeneratedDocumentStatus;
use App\Models\Employee;
use App\Models\HrGeneratedDocument;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<HrGeneratedDocument>
 */
class HrGeneratedDocumentFactory extends Factory
{
    /**
     * Define the model's default state. Defaults to `approved` — the
     * closest replacement for the pre-Checkpoint-37 `generated` default
     * (an already-finalized document), matching most existing tests'
     * assumptions (view/PDF-download/archive). Tests exercising the
     * workflow itself use the states below.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'employee_id' => Employee::factory(),
            'title' => ucfirst(fake()->unique()->words(3, true)).' Letter',
            'document_type' => HrDocumentType::EmploymentLetter,
            'status' => HrGeneratedDocumentStatus::Approved,
            'rendered_content' => "Dear Jane Doe,\n\nThis letter confirms your employment.",
            'generated_at' => now(),
            'approved_at' => now(),
        ];
    }

    public function draft(): static
    {
        return $this->state(fn (array $attributes) => ['status' => HrGeneratedDocumentStatus::Draft, 'approved_at' => null]);
    }

    public function pendingApproval(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => HrGeneratedDocumentStatus::PendingApproval,
            'submitted_at' => now(),
            'approved_at' => null,
        ]);
    }

    public function rejected(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => HrGeneratedDocumentStatus::Rejected,
            'submitted_at' => now(),
            'rejected_at' => now(),
            'rejection_reason' => 'Needs revision.',
            'approved_at' => null,
        ]);
    }
}
