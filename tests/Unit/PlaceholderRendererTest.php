<?php

namespace Tests\Unit;

use App\Enums\EmploymentType;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Location;
use App\Models\Position;
use App\Models\Tenant;
use App\Services\HrDocuments\PlaceholderRenderer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Checkpoint 34 — strict allowlist substitution. Every case here proves
 * the renderer only ever does key-based string substitution (`strtr()`)
 * over a fixed, hardcoded map: no Blade compilation, no eval, no
 * reflection/property access driven by the template string itself.
 */
class PlaceholderRendererTest extends TestCase
{
    use RefreshDatabase;

    public function test_allowed_placeholders_are_substituted_with_real_employee_and_tenant_values(): void
    {
        $tenant = Tenant::factory()->create(['name' => 'Acme Corp']);
        $department = Department::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Engineering']);
        $position = Position::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Software Engineer']);
        $location = Location::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Lagos Office']);
        $employee = Employee::factory()->create([
            'tenant_id' => $tenant->id,
            'first_name' => 'Jane',
            'last_name' => 'Doe',
            'employee_number' => 'EMP-00042',
            'work_email' => 'jane.doe@acme.test',
            'department_id' => $department->id,
            'position_id' => $position->id,
            'location_id' => $location->id,
            'employment_type' => EmploymentType::FullTime,
            'start_date' => '2026-01-15',
        ])->load(['department', 'position', 'location']);

        $template = 'Dear {{employee.name}} ({{employee.employee_number}}), '
            .'you work in {{employee.department}} as {{employee.position}} at {{employee.location}}, '
            .'a {{employee.employment_type}} employee since {{employee.start_date}}. '
            .'Contact: {{employee.email}}. Issued by {{tenant.name}} on {{today}}.';

        $rendered = PlaceholderRenderer::render($template, $employee, $tenant);

        $this->assertStringContainsString('Dear Jane Doe (EMP-00042)', $rendered);
        $this->assertStringContainsString('Engineering', $rendered);
        $this->assertStringContainsString('Software Engineer', $rendered);
        $this->assertStringContainsString('Lagos Office', $rendered);
        $this->assertStringContainsString('full time employee', $rendered);
        $this->assertStringContainsString('2026-01-15', $rendered);
        $this->assertStringContainsString('jane.doe@acme.test', $rendered);
        $this->assertStringContainsString('Acme Corp', $rendered);
        $this->assertStringContainsString(now()->toDateString(), $rendered);
        $this->assertStringNotContainsString('{{', $rendered);
    }

    /**
     * Unknown/unsafe tokens are never matched by strtr()'s fixed map, so
     * they pass through completely unchanged — never executed, never an
     * error. This is the core safety property: an attacker-controlled
     * template cannot smuggle in a token that resolves to arbitrary code
     * execution, reflection, or property access.
     */
    public function test_unknown_placeholders_pass_through_unchanged(): void
    {
        $tenant = Tenant::factory()->create();
        $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);

        $template = 'Balance due: {{employee.salary}}. Secret: {{system.env.APP_KEY}}. '
            .'PHP: {{ eval("phpinfo();") }}. Method call: {{employee.delete()}}.';

        $rendered = PlaceholderRenderer::render($template, $employee, $tenant);

        $this->assertSame($template, $rendered);
    }

    /**
     * Only the exact allowlisted tokens are recognized — a near-miss
     * (wrong casing, missing brace, unknown field name) is treated as
     * unknown and left completely untouched rather than fuzzy-matched.
     */
    public function test_malformed_or_near_miss_tokens_are_left_untouched(): void
    {
        $tenant = Tenant::factory()->create(['name' => 'Acme Corp']);
        $employee = Employee::factory()->create(['tenant_id' => $tenant->id, 'first_name' => 'Jane', 'last_name' => 'Doe']);

        $template = '{{Employee.Name}} and {employee.name} and {{employee.unknown_field}}';

        $rendered = PlaceholderRenderer::render($template, $employee, $tenant);

        $this->assertSame($template, $rendered);
        $this->assertStringNotContainsString('Jane Doe', $rendered);
    }

    public function test_null_relations_render_as_empty_string_not_null_or_error(): void
    {
        $tenant = Tenant::factory()->create();
        $employee = Employee::factory()->create([
            'tenant_id' => $tenant->id,
            'department_id' => null,
            'position_id' => null,
            'location_id' => null,
        ]);

        $rendered = PlaceholderRenderer::render(
            'Dept: [{{employee.department}}] Pos: [{{employee.position}}] Loc: [{{employee.location}}]',
            $employee,
            $tenant,
        );

        $this->assertSame('Dept: [] Pos: [] Loc: []', $rendered);
    }
}
