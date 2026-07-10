<?php

namespace Tests\Feature;

use App\Enums\LifecycleProcessType;
use App\Models\Employee;
use App\Models\EmployeeDocument;
use App\Models\HrDocumentTemplate;
use App\Models\LeaveBalance;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\LifecycleTaskTemplate;
use App\Models\Policy;
use App\Models\PolicyAcknowledgement;
use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Checkpoint 26 — DemoDataSeeder runs as part of the full DatabaseSeeder
 * chain (it depends on TenantSeeder/RoleSeeder/UserSeeder having already
 * run, for the uesl tenant/roles/demo users it links against). This
 * proves the seeder is safe to run repeatedly (idempotent) and produces
 * the coverage the demo guide promises, with no orphaned foreign keys —
 * required check "demo data seeds successfully" / "no orphaned foreign
 * keys" from this checkpoint's completion checklist.
 */
class DemoDataSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_demo_data_seeds_successfully_with_expected_coverage(): void
    {
        $this->seed(DatabaseSeeder::class);

        $tenant = Tenant::query()->where('subdomain', 'uesl')->firstOrFail();

        $this->assertGreaterThanOrEqual(10, Employee::query()->where('tenant_id', $tenant->id)->count());
        $this->assertLessThanOrEqual(15, Employee::query()->where('tenant_id', $tenant->id)->count());

        $this->assertSame(4, Employee::query()->where('tenant_id', $tenant->id)->whereNotNull('user_id')->count());
        $this->assertSame(1, Employee::query()->where('tenant_id', $tenant->id)->where('status', 'inactive')->count());

        $this->assertSame(3, LeaveType::query()->where('tenant_id', $tenant->id)->count());
        $this->assertGreaterThan(0, LeaveBalance::query()->where('tenant_id', $tenant->id)->count());

        $statuses = LeaveRequest::query()->where('tenant_id', $tenant->id)->pluck('status')->map(fn ($status) => $status->value)->all();
        $this->assertContains('pending', $statuses);
        $this->assertContains('approved', $statuses);
        $this->assertContains('rejected', $statuses);

        $this->assertSame(4, EmployeeDocument::query()->where('tenant_id', $tenant->id)->count());
        $this->assertTrue(EmployeeDocument::query()->where('tenant_id', $tenant->id)->where('is_sensitive', true)->exists());
        $this->assertTrue(EmployeeDocument::query()->where('tenant_id', $tenant->id)->whereNotNull('expiry_date')->exists());

        $this->assertSame(3, Policy::query()->where('tenant_id', $tenant->id)->count());
        $this->assertTrue(Policy::query()->where('tenant_id', $tenant->id)->where('status', 'draft')->exists());
        $this->assertTrue(Policy::query()->where('tenant_id', $tenant->id)->where('status', 'published')->exists());

        $ackStatuses = PolicyAcknowledgement::query()->where('tenant_id', $tenant->id)->pluck('acknowledgement_status')->map(fn ($status) => $status->value)->all();
        $this->assertContains('pending', $ackStatuses);
        $this->assertContains('acknowledged', $ackStatuses);

        foreach (['hr.officer', 'line.manager', 'auditor'] as $localPart) {
            $this->assertTrue(
                User::query()->where('tenant_id', $tenant->id)->where('email', "{$localPart}@uesl.peopleos.test")->exists(),
                "Expected demo user {$localPart}@uesl.peopleos.test to exist.",
            );
        }

        // Checkpoint 38 — 8 starter HR document templates, each with a
        // published version 1 using only allowlisted placeholders.
        $templates = HrDocumentTemplate::query()->where('tenant_id', $tenant->id)->with('currentVersion')->get();
        $this->assertCount(8, $templates);

        $allowedPlaceholders = [
            '{{employee.name}}', '{{employee.employee_number}}', '{{employee.email}}',
            '{{employee.department}}', '{{employee.position}}', '{{employee.location}}',
            '{{employee.employment_type}}', '{{employee.start_date}}', '{{tenant.name}}', '{{today}}',
        ];

        foreach ($templates as $template) {
            $this->assertSame('active', $template->status->value, "Starter template '{$template->title}' should be active.");
            $this->assertNotNull($template->currentVersion, "Starter template '{$template->title}' should have a current version.");
            $this->assertSame('published', $template->currentVersion->status->value, "Starter template '{$template->title}' version should be published.");

            preg_match_all('/\{\{[^}]*\}\}/', $template->currentVersion->content_template, $matches);
            foreach ($matches[0] as $token) {
                $this->assertContains(
                    $token,
                    $allowedPlaceholders,
                    "Starter template '{$template->title}' uses an unapproved placeholder: {$token}",
                );
            }
        }

        // Checkpoint 42 — 5 onboarding + 4 offboarding starter task templates.
        $taskTemplates = LifecycleTaskTemplate::query()->where('tenant_id', $tenant->id)->get();
        $this->assertCount(9, $taskTemplates);
        $this->assertSame(5, $taskTemplates->where('type', LifecycleProcessType::Onboarding)->count());
        $this->assertSame(4, $taskTemplates->where('type', LifecycleProcessType::Offboarding)->count());
    }

    public function test_demo_data_seeder_has_no_orphaned_foreign_keys(): void
    {
        $this->seed(DatabaseSeeder::class);

        $tenant = Tenant::query()->where('subdomain', 'uesl')->firstOrFail();

        $orphanedManagers = Employee::query()
            ->where('tenant_id', $tenant->id)
            ->whereNotNull('manager_employee_id')
            ->whereDoesntHave('manager')
            ->count();

        $this->assertSame(0, $orphanedManagers);

        $orphanedDepartments = Employee::query()
            ->where('tenant_id', $tenant->id)
            ->whereNotNull('department_id')
            ->whereDoesntHave('department')
            ->count();

        $this->assertSame(0, $orphanedDepartments);

        $orphanedDocumentEmployees = EmployeeDocument::query()
            ->where('tenant_id', $tenant->id)
            ->whereDoesntHave('employee')
            ->count();

        $this->assertSame(0, $orphanedDocumentEmployees);
    }

    public function test_demo_data_seeder_is_idempotent_on_a_second_run(): void
    {
        $this->seed(DatabaseSeeder::class);
        $tenant = Tenant::query()->where('subdomain', 'uesl')->firstOrFail();
        $firstRunCount = Employee::query()->where('tenant_id', $tenant->id)->count();

        $this->seed(DatabaseSeeder::class);
        $secondRunCount = Employee::query()->where('tenant_id', $tenant->id)->count();

        $this->assertSame($firstRunCount, $secondRunCount);
    }
}
