<?php

namespace Database\Factories;

use App\Enums\HrDocumentTemplateVersionStatus;
use App\Models\HrDocumentTemplate;
use App\Models\HrDocumentTemplateVersion;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<HrDocumentTemplateVersion>
 */
class HrDocumentTemplateVersionFactory extends Factory
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
            'hr_document_template_id' => HrDocumentTemplate::factory(),
            'version_number' => 1,
            'content_template' => "Dear {{employee.name}},\n\nThis letter confirms your employment as {{employee.position}} in {{employee.department}}, effective {{employee.start_date}}.\n\n{{tenant.name}}\n{{today}}",
            'status' => HrDocumentTemplateVersionStatus::Draft,
        ];
    }

    public function published(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => HrDocumentTemplateVersionStatus::Published,
            'published_at' => now(),
        ]);
    }

    public function archived(): static
    {
        return $this->state(fn (array $attributes) => ['status' => HrDocumentTemplateVersionStatus::Archived]);
    }
}
