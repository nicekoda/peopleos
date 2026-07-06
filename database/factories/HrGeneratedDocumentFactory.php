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
     * Define the model's default state.
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
            'status' => HrGeneratedDocumentStatus::Generated,
            'rendered_content' => "Dear Jane Doe,\n\nThis letter confirms your employment.",
            'generated_at' => now(),
        ];
    }
}
