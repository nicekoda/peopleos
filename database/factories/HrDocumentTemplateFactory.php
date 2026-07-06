<?php

namespace Database\Factories;

use App\Enums\HrDocumentTemplateStatus;
use App\Enums\HrDocumentType;
use App\Models\HrDocumentTemplate;
use App\Models\HrDocumentTemplateVersion;
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
            'status' => HrDocumentTemplateStatus::Active,
        ];
    }

    /**
     * Checkpoint 36 — a template with no current_version_id is a real,
     * testable edge case (an active template that's never had content
     * published), but every other test needs a realistic template with
     * content ready to generate from — so a published version 1 is
     * created by default here, same as the seeded/backfilled shape every
     * real template has. Tests wanting the edge case null out
     * current_version_id explicitly afterward.
     */
    public function configure(): static
    {
        return $this->afterCreating(function (HrDocumentTemplate $template): void {
            if ($template->current_version_id !== null) {
                return;
            }

            $version = HrDocumentTemplateVersion::factory()->published()->create([
                'tenant_id' => $template->tenant_id,
                'hr_document_template_id' => $template->id,
                'version_number' => 1,
            ]);

            $template->forceFill(['current_version_id' => $version->id])->save();
        });
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => ['status' => HrDocumentTemplateStatus::Inactive]);
    }
}
