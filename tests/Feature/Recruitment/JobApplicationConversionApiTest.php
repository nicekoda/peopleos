<?php

namespace Tests\Feature\Recruitment;

use App\Enums\ApplicationStage;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\Permission;
use App\Models\RecruitmentApplicant;
use App\Models\RecruitmentApplication;
use App\Models\RecruitmentJob;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class JobApplicationConversionApiTest extends TestCase
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
     * An eligible application: stage hired, ready_for_conversion true,
     * not yet converted. Applicant explicitly created within the same
     * tenant — RecruitmentApplicationFactory's own applicant nesting
     * would otherwise default to a different, randomly-generated tenant
     * (RecruitmentApplicantFactory always sets its own tenant_id), the
     * same limitation LifecycleProcessFactory/EmployeeFactory already
     * have (see docs/testing.md).
     */
    protected function eligibleApplication(Tenant $tenant, ?RecruitmentJob $job = null): RecruitmentApplication
    {
        $applicant = RecruitmentApplicant::factory()->create(['tenant_id' => $tenant->id]);

        return RecruitmentApplication::factory()->create([
            'tenant_id' => $tenant->id,
            'recruitment_applicant_id' => $applicant->id,
            ...($job !== null ? ['recruitment_job_id' => $job->id] : []),
            'stage' => ApplicationStage::Hired,
            'ready_for_conversion' => true,
        ]);
    }

    protected function validConversionPayload(): array
    {
        return [
            'employee_number' => 'EMP-CONV-001',
            'work_email' => 'converted.candidate@example.com',
            'start_date' => now()->addWeek()->toDateString(),
            'employment_type' => 'full_time',
        ];
    }

    // 1: guest cannot convert
    public function test_guest_cannot_convert(): void
    {
        $tenant = Tenant::factory()->create();
        $application = $this->eligibleApplication($tenant);

        $this->postJson($this->url($tenant, "job-applications/{$application->id}/convert-to-employee"), $this->validConversionPayload())
            ->assertUnauthorized();
    }

    // 2: user without permission cannot convert
    public function test_user_without_permission_cannot_convert(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'job_applications.view');
        $application = $this->eligibleApplication($tenant);

        $response = $this->actingAs($user)->postJson(
            $this->url($tenant, "job-applications/{$application->id}/convert-to-employee"),
            $this->validConversionPayload(),
        );

        $response->assertForbidden();
    }

    // 3: HR Manager/Tenant Admin can convert eligible same-tenant application
    public function test_hr_manager_can_convert_eligible_application(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'job_applications.convert_to_employee');
        $application = $this->eligibleApplication($tenant);

        $response = $this->actingAs($user)->postJson(
            $this->url($tenant, "job-applications/{$application->id}/convert-to-employee"),
            $this->validConversionPayload(),
        );

        $response->assertOk();
        $this->assertDatabaseHas('employees', [
            'tenant_id' => $tenant->id,
            'employee_number' => 'EMP-CONV-001',
            'work_email' => 'converted.candidate@example.com',
            'employment_type' => 'full_time',
        ]);
    }

    // 4: HR Officer cannot convert unless explicitly granted
    public function test_hr_officer_cannot_convert_by_default(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions(
            $tenant,
            'job_applications.view', 'job_applications.create', 'job_applications.update',
            'job_applications.update_stage', 'job_applications.add_note', 'job_applications.mark_ready_for_conversion',
        );
        $application = $this->eligibleApplication($tenant);

        $response = $this->actingAs($user)->postJson(
            $this->url($tenant, "job-applications/{$application->id}/convert-to-employee"),
            $this->validConversionPayload(),
        );

        $response->assertForbidden();
    }

    // 5: Auditor cannot convert
    public function test_auditor_cannot_convert(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'job_applications.view');
        $application = $this->eligibleApplication($tenant);

        $response = $this->actingAs($user)->postJson(
            $this->url($tenant, "job-applications/{$application->id}/convert-to-employee"),
            $this->validConversionPayload(),
        );

        $response->assertForbidden();
    }

    // 6: Employee cannot convert
    public function test_employee_cannot_convert(): void
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        $application = $this->eligibleApplication($tenant);

        $response = $this->actingAs($user)->postJson(
            $this->url($tenant, "job-applications/{$application->id}/convert-to-employee"),
            $this->validConversionPayload(),
        );

        $response->assertForbidden();
    }

    // 7: Platform Super Admin is blocked
    public function test_platform_super_admin_is_blocked(): void
    {
        $tenant = Tenant::factory()->create();
        $admin = User::factory()->platformAdmin()->create();
        $application = $this->eligibleApplication($tenant);

        $response = $this->actingAs($admin)->postJson(
            $this->url($tenant, "job-applications/{$application->id}/convert-to-employee"),
            $this->validConversionPayload(),
        );

        $response->assertForbidden();
    }

    // 8: Tenant A cannot convert Tenant B application
    public function test_tenant_a_cannot_convert_tenant_b_application(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();
        $userA = $this->userWithPermissions($tenantA, 'job_applications.convert_to_employee');
        $applicationB = $this->eligibleApplication($tenantB);

        $response = $this->actingAs($userA)->postJson(
            $this->url($tenantA, "job-applications/{$applicationB->id}/convert-to-employee"),
            $this->validConversionPayload(),
        );

        $response->assertNotFound();
        $this->assertNull($applicationB->fresh()->converted_employee_id);
    }

    // 9: application must be ready for conversion (stage hired AND ready_for_conversion true)
    public function test_application_not_at_hired_stage_cannot_be_converted(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'job_applications.convert_to_employee');
        $application = RecruitmentApplication::factory()->create([
            'tenant_id' => $tenant->id,
            'stage' => ApplicationStage::Offer,
            'ready_for_conversion' => true,
        ]);

        $response = $this->actingAs($user)->postJson(
            $this->url($tenant, "job-applications/{$application->id}/convert-to-employee"),
            $this->validConversionPayload(),
        );

        $response->assertStatus(422);
        $this->assertNull($application->fresh()->converted_employee_id);
    }

    public function test_application_not_marked_ready_cannot_be_converted(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'job_applications.convert_to_employee');
        $application = RecruitmentApplication::factory()->create([
            'tenant_id' => $tenant->id,
            'stage' => ApplicationStage::Hired,
            'ready_for_conversion' => false,
        ]);

        $response = $this->actingAs($user)->postJson(
            $this->url($tenant, "job-applications/{$application->id}/convert-to-employee"),
            $this->validConversionPayload(),
        );

        $response->assertStatus(422);
        $this->assertNull($application->fresh()->converted_employee_id);
    }

    // 10: application cannot be converted twice
    public function test_application_cannot_be_converted_twice(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'job_applications.convert_to_employee');
        $application = $this->eligibleApplication($tenant);

        $this->actingAs($user)->postJson(
            $this->url($tenant, "job-applications/{$application->id}/convert-to-employee"),
            $this->validConversionPayload(),
        )->assertOk();

        $secondAttempt = $this->actingAs($user)->postJson(
            $this->url($tenant, "job-applications/{$application->id}/convert-to-employee"),
            ['employee_number' => 'EMP-CONV-002', 'employment_type' => 'full_time'],
        );

        $secondAttempt->assertStatus(422);
        $this->assertSame(1, Employee::query()->where('tenant_id', $tenant->id)->count());
    }

    // 11: employee number must be unique per tenant
    public function test_employee_number_must_be_unique_per_tenant(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'job_applications.convert_to_employee');
        Employee::factory()->create(['tenant_id' => $tenant->id, 'employee_number' => 'EMP-CONV-001']);
        $application = $this->eligibleApplication($tenant);

        $response = $this->actingAs($user)->postJson(
            $this->url($tenant, "job-applications/{$application->id}/convert-to-employee"),
            $this->validConversionPayload(),
        );

        $response->assertStatus(422)->assertJsonValidationErrors('employee_number');
        $this->assertNull($application->fresh()->converted_employee_id);
    }

    // 12: work email must be unique per tenant if applicable
    public function test_work_email_must_be_unique_per_tenant(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'job_applications.convert_to_employee');
        Employee::factory()->create(['tenant_id' => $tenant->id, 'work_email' => 'converted.candidate@example.com']);
        $application = $this->eligibleApplication($tenant);

        $response = $this->actingAs($user)->postJson(
            $this->url($tenant, "job-applications/{$application->id}/convert-to-employee"),
            $this->validConversionPayload(),
        );

        $response->assertStatus(422)->assertJsonValidationErrors('work_email');
        $this->assertNull($application->fresh()->converted_employee_id);
    }

    // 13/14: conversion creates the employee in the same tenant and links the application to it
    public function test_conversion_creates_employee_in_same_tenant_and_links_application(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'job_applications.convert_to_employee');
        $application = $this->eligibleApplication($tenant);

        $response = $this->actingAs($user)->postJson(
            $this->url($tenant, "job-applications/{$application->id}/convert-to-employee"),
            $this->validConversionPayload(),
        );

        $response->assertOk();
        $application->refresh();
        $this->assertNotNull($application->converted_employee_id);
        $this->assertNotNull($application->converted_at);
        $this->assertSame($user->id, $application->converted_by);

        $employee = Employee::query()->findOrFail($application->converted_employee_id);
        $this->assertSame($tenant->id, $employee->tenant_id);
        $this->assertSame($application->applicant->first_name, $employee->first_name);
        $this->assertSame($application->applicant->last_name, $employee->last_name);
    }

    // Field mapping: department/position/location/employment_type pre-fillable from the job, still validated
    public function test_conversion_maps_job_fields_when_provided(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'job_applications.convert_to_employee');
        $job = RecruitmentJob::factory()->create(['tenant_id' => $tenant->id, 'employment_type' => 'contractor']);
        $application = $this->eligibleApplication($tenant, $job);

        $response = $this->actingAs($user)->postJson(
            $this->url($tenant, "job-applications/{$application->id}/convert-to-employee"),
            ['employee_number' => 'EMP-CONV-003', 'employment_type' => 'contractor'],
        );

        $response->assertOk();
        $this->assertDatabaseHas('employees', ['tenant_id' => $tenant->id, 'employee_number' => 'EMP-CONV-003', 'employment_type' => 'contractor']);
    }

    // 15: conversion fields are server-controlled — forged input ignored
    public function test_forged_conversion_fields_are_ignored(): void
    {
        $tenant = Tenant::factory()->create();
        $otherTenant = Tenant::factory()->create();
        $otherUser = User::factory()->create(['tenant_id' => $otherTenant->id]);
        $forgedEmployee = Employee::factory()->create(['tenant_id' => $otherTenant->id]);
        $user = $this->userWithPermissions($tenant, 'job_applications.convert_to_employee');
        $application = $this->eligibleApplication($tenant);

        $response = $this->actingAs($user)->postJson(
            $this->url($tenant, "job-applications/{$application->id}/convert-to-employee"),
            [
                ...$this->validConversionPayload(),
                'tenant_id' => $otherTenant->id,
                'converted_employee_id' => $forgedEmployee->id,
                'converted_at' => now()->subYear()->toDateTimeString(),
                'converted_by' => $otherUser->id,
                'created_by' => $otherUser->id,
            ],
        );

        $response->assertOk();
        $application->refresh();
        $this->assertSame($tenant->id, $application->tenant_id);
        $this->assertNotSame($forgedEmployee->id, $application->converted_employee_id);
        $this->assertSame($user->id, $application->converted_by);

        $employee = Employee::query()->findOrFail($application->converted_employee_id);
        $this->assertSame($tenant->id, $employee->tenant_id);
        $this->assertSame($user->id, $employee->created_by);
        $this->assertSame('draft', $employee->status->value);
    }

    // 16: conversion is transactional — a validation failure leaves no partial state
    public function test_failed_conversion_leaves_no_partial_state(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'job_applications.convert_to_employee');
        Employee::factory()->create(['tenant_id' => $tenant->id, 'employee_number' => 'EMP-CONV-001']);
        $application = $this->eligibleApplication($tenant);
        $employeeCountBefore = Employee::query()->where('tenant_id', $tenant->id)->count();

        $this->actingAs($user)->postJson(
            $this->url($tenant, "job-applications/{$application->id}/convert-to-employee"),
            $this->validConversionPayload(),
        )->assertStatus(422);

        $this->assertSame($employeeCountBefore, Employee::query()->where('tenant_id', $tenant->id)->count());
        $application->refresh();
        $this->assertNull($application->converted_employee_id);
        $this->assertNull($application->converted_at);
        $this->assertNull($application->converted_by);
        $this->assertSame(ApplicationStage::Hired, $application->stage);
        $this->assertTrue($application->ready_for_conversion);
    }

    // 17: audit logs are written safely — never the cover letter/notes
    public function test_conversion_writes_audit_logs_without_sensitive_content(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'job_applications.convert_to_employee');
        $application = $this->eligibleApplication($tenant);
        $application->update(['cover_letter' => 'CONFIDENTIAL_COVER_LETTER_TEXT']);

        $this->actingAs($user)->postJson(
            $this->url($tenant, "job-applications/{$application->id}/convert-to-employee"),
            $this->validConversionPayload(),
        )->assertOk();

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'job_application.converted_to_employee',
            'module' => 'recruitment',
            'actor_user_id' => $user->id,
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'employee.created_from_recruitment',
            'module' => 'employees',
            'actor_user_id' => $user->id,
        ]);

        $logs = AuditLog::query()->whereIn('action', ['job_application.converted_to_employee', 'employee.created_from_recruitment'])->get();
        foreach ($logs as $log) {
            $this->assertStringNotContainsString('CONFIDENTIAL_COVER_LETTER_TEXT', json_encode($log->toArray()));
        }
    }

    // Resource exposes the converted employee link once set
    public function test_show_exposes_converted_employee_link(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'job_applications.view', 'job_applications.convert_to_employee');
        $application = $this->eligibleApplication($tenant);

        $this->actingAs($user)->postJson(
            $this->url($tenant, "job-applications/{$application->id}/convert-to-employee"),
            $this->validConversionPayload(),
        )->assertOk();

        $response = $this->actingAs($user)->getJson($this->url($tenant, "job-applications/{$application->id}"));

        $response->assertOk();
        $response->assertJsonPath('data.converted_employee.employee_number', 'EMP-CONV-001');
    }
}
