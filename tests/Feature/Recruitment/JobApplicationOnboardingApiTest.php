<?php

namespace Tests\Feature\Recruitment;

use App\Enums\ApplicationStage;
use App\Enums\LifecycleProcessStatus;
use App\Enums\LifecycleProcessType;
use App\Models\Employee;
use App\Models\LifecycleProcess;
use App\Models\LifecycleTaskTemplate;
use App\Models\Permission;
use App\Models\RecruitmentApplicant;
use App\Models\RecruitmentApplication;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Checkpoint 41 — Recruitment-to-Onboarding Handoff Foundation.
 * Mirrors JobApplicationConversionApiTest's conventions (same helper
 * shapes, same tenant/permission assertion style) for the new
 * POST /job-applications/{jobApplication}/start-onboarding endpoint.
 */
class JobApplicationOnboardingApiTest extends TestCase
{
    use RefreshDatabase;

    protected function userWithPermissions(Tenant $tenant, string ...$permissionKeys): User
    {
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        $role = Role::factory()->create(['tenant_id' => $tenant->id]);

        foreach ($permissionKeys as $key) {
            $permission = Permission::query()->firstOrCreate(
                ['key' => $key],
                ['category' => explode('.', $key)[0], 'is_platform_permission' => false],
            );
            $role->givePermissionTo($permission);
        }

        $user->assignRole($role);

        return $user;
    }

    protected function url(Tenant $tenant, string $path): string
    {
        return 'http://'.$tenant->subdomain.'.'.config('tenancy.base_domain').'/api/v1/'.$path;
    }

    /**
     * An application already in the "converted" state, pointing at a
     * real Employee in the same tenant — built directly via factory
     * state rather than driving the actual convert-to-employee endpoint,
     * the same "helper produces a resource already in state X" pattern
     * PolicyApiTest's publishedPolicy() established.
     */
    protected function convertedApplication(Tenant $tenant, ?Employee $employee = null): RecruitmentApplication
    {
        $applicant = RecruitmentApplicant::factory()->create(['tenant_id' => $tenant->id]);
        $employee ??= Employee::factory()->create(['tenant_id' => $tenant->id]);
        $convertedBy = User::factory()->create(['tenant_id' => $tenant->id]);

        return RecruitmentApplication::factory()->create([
            'tenant_id' => $tenant->id,
            'recruitment_applicant_id' => $applicant->id,
            'stage' => ApplicationStage::Hired,
            'ready_for_conversion' => true,
            'converted_employee_id' => $employee->id,
            'converted_at' => now(),
            'converted_by' => $convertedBy->id,
        ]);
    }

    // 1: guest cannot start onboarding
    public function test_guest_cannot_start_onboarding(): void
    {
        $tenant = Tenant::factory()->create();
        $application = $this->convertedApplication($tenant);

        $this->postJson($this->url($tenant, "job-applications/{$application->id}/start-onboarding"))
            ->assertUnauthorized();
    }

    // 2: user without lifecycle.create cannot start onboarding
    public function test_user_without_permission_cannot_start_onboarding(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'job_applications.view', 'job_applications.convert_to_employee');
        $application = $this->convertedApplication($tenant);

        $response = $this->actingAs($user)->postJson($this->url($tenant, "job-applications/{$application->id}/start-onboarding"));

        $response->assertForbidden();
        $this->assertNull($application->fresh()->onboarding_process_id);
    }

    // 3: user with lifecycle.create can start onboarding for a converted application
    public function test_user_with_lifecycle_create_can_start_onboarding_for_converted_application(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'lifecycle.create');
        $application = $this->convertedApplication($tenant);

        $response = $this->actingAs($user)->postJson($this->url($tenant, "job-applications/{$application->id}/start-onboarding"));

        $response->assertOk();
        $this->assertNotNull($application->fresh()->onboarding_process_id);
    }

    // 4: Platform Super Admin is blocked
    public function test_platform_super_admin_is_blocked_from_starting_onboarding(): void
    {
        $tenant = Tenant::factory()->create();
        $admin = User::factory()->platformAdmin()->create();
        $application = $this->convertedApplication($tenant);

        $response = $this->actingAs($admin)->postJson($this->url($tenant, "job-applications/{$application->id}/start-onboarding"));

        $response->assertForbidden();
    }

    // 5: Tenant A cannot start onboarding for Tenant B's application
    public function test_tenant_a_cannot_start_onboarding_for_tenant_b_application(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();
        $userA = $this->userWithPermissions($tenantA, 'lifecycle.create');
        $applicationB = $this->convertedApplication($tenantB);

        $response = $this->actingAs($userA)->postJson($this->url($tenantA, "job-applications/{$applicationB->id}/start-onboarding"));

        $response->assertNotFound();
        $this->assertNull($applicationB->fresh()->onboarding_process_id);
    }

    // 6: an application that hasn't been converted yet cannot start onboarding
    public function test_application_not_yet_converted_cannot_start_onboarding(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'lifecycle.create');
        $applicant = RecruitmentApplicant::factory()->create(['tenant_id' => $tenant->id]);
        $application = RecruitmentApplication::factory()->create([
            'tenant_id' => $tenant->id,
            'recruitment_applicant_id' => $applicant->id,
            'stage' => ApplicationStage::Hired,
            'ready_for_conversion' => true,
        ]);

        $response = $this->actingAs($user)->postJson($this->url($tenant, "job-applications/{$application->id}/start-onboarding"));

        $response->assertStatus(422);
        $this->assertNull($application->fresh()->onboarding_process_id);
        $this->assertDatabaseCount('employee_lifecycle_processes', 0);
    }

    // 7: onboarding cannot be started twice for the same application
    public function test_onboarding_cannot_be_started_twice_for_same_application(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'lifecycle.create');
        $application = $this->convertedApplication($tenant);

        $this->actingAs($user)->postJson($this->url($tenant, "job-applications/{$application->id}/start-onboarding"))->assertOk();
        $firstProcessId = $application->fresh()->onboarding_process_id;

        $second = $this->actingAs($user)->postJson($this->url($tenant, "job-applications/{$application->id}/start-onboarding"));

        $second->assertStatus(422);
        $this->assertSame($firstProcessId, $application->fresh()->onboarding_process_id);
        $this->assertSame(1, LifecycleProcess::query()->where('employee_id', $application->converted_employee_id)->count());
    }

    // 8: cannot start onboarding when the converted employee already has an active (draft/in_progress) onboarding process
    public function test_cannot_start_onboarding_when_employee_has_an_active_onboarding_process_already(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'lifecycle.create');
        $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);
        LifecycleProcess::factory()->create([
            'tenant_id' => $tenant->id,
            'employee_id' => $employee->id,
            'type' => LifecycleProcessType::Onboarding,
            'status' => LifecycleProcessStatus::InProgress,
        ]);
        $application = $this->convertedApplication($tenant, $employee);

        $response = $this->actingAs($user)->postJson($this->url($tenant, "job-applications/{$application->id}/start-onboarding"));

        $response->assertStatus(422);
        $this->assertNull($application->fresh()->onboarding_process_id);
        $this->assertSame(1, LifecycleProcess::query()->where('employee_id', $employee->id)->count());
    }

    // 9: a prior *completed* onboarding process does not block starting a new one
    public function test_new_onboarding_can_start_when_employee_has_only_a_completed_onboarding_process(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'lifecycle.create');
        $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);
        LifecycleProcess::factory()->completed()->create([
            'tenant_id' => $tenant->id,
            'employee_id' => $employee->id,
            'type' => LifecycleProcessType::Onboarding,
        ]);
        $application = $this->convertedApplication($tenant, $employee);

        $response = $this->actingAs($user)->postJson($this->url($tenant, "job-applications/{$application->id}/start-onboarding"));

        $response->assertOk();
        $this->assertSame(2, LifecycleProcess::query()->where('employee_id', $employee->id)->count());
    }

    // 10: starting onboarding creates a draft LifecycleProcess and links it back to the application
    public function test_start_onboarding_creates_draft_lifecycle_process_linked_to_application(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'lifecycle.create');
        $application = $this->convertedApplication($tenant);

        $this->actingAs($user)->postJson($this->url($tenant, "job-applications/{$application->id}/start-onboarding"))->assertOk();

        $application->refresh();
        $process = LifecycleProcess::query()->findOrFail($application->onboarding_process_id);
        $this->assertSame($tenant->id, $process->tenant_id);
        $this->assertSame($application->converted_employee_id, $process->employee_id);
        $this->assertSame(LifecycleProcessType::Onboarding, $process->type);
        $this->assertSame(LifecycleProcessStatus::Draft, $process->status);
        $this->assertSame($user->id, $process->created_by);
        $this->assertDatabaseCount('employee_lifecycle_tasks', 0);
    }

    // 11: starting onboarding writes both audit log entries
    public function test_start_onboarding_writes_audit_logs(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'lifecycle.create');
        $application = $this->convertedApplication($tenant);

        $this->actingAs($user)->postJson($this->url($tenant, "job-applications/{$application->id}/start-onboarding"))->assertOk();

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'job_application.onboarding_started',
            'module' => 'recruitment',
            'actor_user_id' => $user->id,
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'employee_lifecycle_process.created_from_recruitment',
            'module' => 'lifecycle',
            'actor_user_id' => $user->id,
        ]);
    }

    // 12: the endpoint takes no meaningful body — forged fields cannot influence the created process
    public function test_forged_body_fields_are_ignored_on_start_onboarding(): void
    {
        $tenant = Tenant::factory()->create();
        $otherTenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'lifecycle.create');
        $application = $this->convertedApplication($tenant);

        $response = $this->actingAs($user)->postJson(
            $this->url($tenant, "job-applications/{$application->id}/start-onboarding"),
            [
                'status' => 'completed',
                'type' => 'offboarding',
                'tenant_id' => $otherTenant->id,
                'employee_id' => Employee::factory()->create(['tenant_id' => $otherTenant->id])->id,
            ],
        );

        $response->assertOk();
        $process = LifecycleProcess::query()->findOrFail($application->fresh()->onboarding_process_id);
        $this->assertSame($tenant->id, $process->tenant_id);
        $this->assertSame($application->converted_employee_id, $process->employee_id);
        $this->assertSame(LifecycleProcessType::Onboarding, $process->type);
        $this->assertSame(LifecycleProcessStatus::Draft, $process->status);
    }

    // 13: the show endpoint exposes the onboarding process once started
    public function test_show_exposes_onboarding_process_after_starting(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'job_applications.view', 'lifecycle.create');
        $application = $this->convertedApplication($tenant);

        $this->actingAs($user)->postJson($this->url($tenant, "job-applications/{$application->id}/start-onboarding"))->assertOk();

        $response = $this->actingAs($user)->getJson($this->url($tenant, "job-applications/{$application->id}"));

        $response->assertOk();
        $response->assertJsonPath('data.onboarding_process.status', 'draft');
        $this->assertNotNull($response->json('data.onboarding_process.id'));
    }

    // Checkpoint 42 — starting onboarding this way applies matching
    // onboarding task templates exactly like a direct lifecycle-processes
    // create does (same LifecycleTaskTemplateApplier call).
    public function test_start_onboarding_applies_matching_task_templates(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'lifecycle.create');
        LifecycleTaskTemplate::factory()->onboarding()->create(['tenant_id' => $tenant->id, 'title' => 'Send welcome email']);
        LifecycleTaskTemplate::factory()->offboarding()->create(['tenant_id' => $tenant->id, 'title' => 'Revoke access']);
        $application = $this->convertedApplication($tenant);

        $this->actingAs($user)->postJson($this->url($tenant, "job-applications/{$application->id}/start-onboarding"))->assertOk();

        $processId = $application->fresh()->onboarding_process_id;
        $this->assertDatabaseHas('employee_lifecycle_tasks', ['process_id' => $processId, 'title' => 'Send welcome email']);
        $this->assertDatabaseMissing('employee_lifecycle_tasks', ['process_id' => $processId, 'title' => 'Revoke access']);
    }

    // Checkpoint 47 — the handoff is gated by BOTH modules; disabling
    // either one blocks it, per your explicit requirement that this
    // must disappear when Lifecycle is disabled, not only when
    // Recruitment is.
    public function test_start_onboarding_is_blocked_when_lifecycle_module_is_disabled(): void
    {
        $tenant = Tenant::factory()->create();
        $admin = $this->userWithPermissions($tenant, 'tenant.modules.manage');
        $user = $this->userWithPermissions($tenant, 'lifecycle.create');
        $application = $this->convertedApplication($tenant);

        $this->actingAs($admin)->patchJson($this->url($tenant, 'tenant/modules/lifecycle'), ['enabled' => false])->assertOk();

        $response = $this->actingAs($user)->postJson($this->url($tenant, "job-applications/{$application->id}/start-onboarding"));

        $response->assertForbidden();
        $response->assertJsonPath('reason', 'module_disabled');
        $this->assertNull($application->fresh()->onboarding_process_id);
    }

    public function test_start_onboarding_is_blocked_when_recruitment_module_is_disabled(): void
    {
        $tenant = Tenant::factory()->create();
        $admin = $this->userWithPermissions($tenant, 'tenant.modules.manage');
        $user = $this->userWithPermissions($tenant, 'lifecycle.create');
        $application = $this->convertedApplication($tenant);

        $this->actingAs($admin)->patchJson($this->url($tenant, 'tenant/modules/recruitment'), ['enabled' => false])->assertOk();

        $response = $this->actingAs($user)->postJson($this->url($tenant, "job-applications/{$application->id}/start-onboarding"));

        $response->assertForbidden();
        $response->assertJsonPath('reason', 'module_disabled');
        $this->assertNull($application->fresh()->onboarding_process_id);
    }
}
