<?php

namespace Database\Factories;

use App\Enums\HrDocumentTemplateStatus;
use App\Enums\HrDocumentType;
use App\Models\HrDocumentTemplate;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<HrDocumentTemplate>
 */
class HrDocumentTemplateFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $title = ucfirst(fake()->unique()->words(3, true)).' Template';

        return [
            'tenant_id' => Tenant::factory(),
            'title' => $title,
            'slug' => Str::slug($title),
            'document_type' => HrDocumentType::EmploymentLetter,
            'content_template' => "Dear {{employee.name}},\n\nThis letter confirms your employment as {{employee.position}} in {{employee.department}}, effective {{employee.start_date}}.\n\n{{tenant.name}}\n{{today}}",
            'status' => HrDocumentTemplateStatus::Active,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => ['status' => HrDocumentTemplateStatus::Inactive]);
    }
}
