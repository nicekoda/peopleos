<?php

namespace Tests\Feature\Recruitment;

use App\Enums\ApplicationStage;
use App\Models\AuditLog;
use App\Models\Permission;
use App\Models\RecruitmentApplicant;
use App\Models\RecruitmentApplication;
use App\Models\RecruitmentApplicationNote;
use App\Models\RecruitmentJob;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class JobApplicationApiTest extends TestCase
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

    // 1: guest cannot access job-applications API
    public function test_guest_cannot_access_job_applications_api(): void
    {
        $tenant = Tenant::factory()->create();
        $application = RecruitmentApplication::factory()->create(['tenant_id' => $tenant->id]);

        $this->getJson($this->url($tenant, 'job-applications'))->assertUnauthorized();
        $this->postJson($this->url($tenant, 'job-applications'), [])->assertUnauthorized();
        $this->getJson($this->url($tenant, "job-applications/{$application->id}"))->assertUnauthorized();
        $this->patchJson($this->url($tenant, "job-applications/{$application->id}"), [])->assertUnauthorized();
        $this->deleteJson($this->url($tenant, "job-applications/{$application->id}"))->assertUnauthorized();
        $this->patchJson($this->url($tenant, "job-applications/{$application->id}/stage"), ['stage' => 'screening'])->assertUnauthorized();
        $this->postJson($this->url($tenant, "job-applications/{$application->id}/notes"), ['note' => 'x'])->assertUnauthorized();
    }

    // 2: user without job_applications.view cannot list/view
    public function test_user_without_view_permission_cannot_list_or_view(): void
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        $application = RecruitmentApplication::factory()->create(['tenant_id' => $tenant->id]);

        $this->actingAs($user)->getJson($this->url($tenant, 'job-applications'))->assertForbidden();
        $this->actingAs($user)->getJson($this->url($tenant, "job-applications/{$application->id}"))->assertForbidden();
    }

    // 3: user with permission can list/view same-tenant records
    public function test_user_with_view_permission_can_list_and_view(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'job_applications.view');
        $application = RecruitmentApplication::factory()->create(['tenant_id' => $tenant->id]);

        $this->actingAs($user)->getJson($this->url($tenant, 'job-applications'))->assertOk();
        $this->actingAs($user)->getJson($this->url($tenant, "job-applications/{$application->id}"))->assertOk();
    }

    // 6: user without create permission cannot create
    public function test_user_without_create_permission_cannot_create_application(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'job_applications.view');
        $job = RecruitmentJob::factory()->create(['tenant_id' => $tenant->id]);

        $response = $this->actingAs($user)->postJson($this->url($tenant, 'job-applications'), [
            'recruitment_job_id' => $job->id,
            'first_name' => 'Ada',
            'last_name' => 'Lovelace',
            'email' => 'ada@example.com',
        ]);

        $response->assertForbidden();
    }

    // 7: user with create permission can create (one-step applicant+application)
    public function test_user_with_create_permission_can_create_application(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'job_applications.create');
        $job = RecruitmentJob::factory()->create(['tenant_id' => $tenant->id]);

        $response = $this->actingAs($user)->postJson($this->url($tenant, 'job-applications'), [
            'recruitment_job_id' => $job->id,
            'first_name' => 'Ada',
            'last_name' => 'Lovelace',
            'email' => 'ada@example.com',
            'cover_letter' => 'I would love to join.',
        ]);

        $response->assertCreated();
        $this->assertDatabaseHas('recruitment_applicants', [
            'tenant_id' => $tenant->id,
            'first_name' => 'Ada',
            'last_name' => 'Lovelace',
            'email' => 'ada@example.com',
        ]);
        $this->assertDatabaseHas('recruitment_applications', [
            'tenant_id' => $tenant->id,
            'recruitment_job_id' => $job->id,
            'stage' => 'applied',
            'status' => 'active',
            'ready_for_conversion' => false,
        ]);
    }

    // Cannot create an application against another tenant's job
    public function test_cannot_create_application_for_another_tenants_job(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenantA, 'job_applications.create');
        $jobB = RecruitmentJob::factory()->create(['tenant_id' => $tenantB->id]);

        $response = $this->actingAs($user)->postJson($this->url($tenantA, 'job-applications'), [
            'recruitment_job_id' => $jobB->id,
            'first_name' => 'Ada',
            'last_name' => 'Lovelace',
            'email' => 'ada@example.com',
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('recruitment_job_id');
    }

    // Forged system fields cannot be set via create
    public function test_forged_system_fields_are_ignored_on_create(): void
    {
        $tenant = Tenant::factory()->create();
        $otherTenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'job_applications.create');
        $job = RecruitmentJob::factory()->create(['tenant_id' => $tenant->id]);

        $response = $this->actingAs($user)->postJson($this->url($tenant, 'job-applications'), [
            'recruitment_job_id' => $job->id,
            'first_name' => 'Ada',
            'last_name' => 'Lovelace',
            'email' => 'ada@example.com',
            'tenant_id' => $otherTenant->id,
            'stage' => 'hired',
            'ready_for_conversion' => true,
        ]);

        $response->assertCreated();
        $application = RecruitmentApplication::query()->findOrFail($response->json('data.id'));
        $this->assertSame($tenant->id, $application->tenant_id);
        $this->assertSame('applied', $application->stage->value);
        $this->assertFalse($application->ready_for_conversion);
    }

    // 8: user without update permission cannot update
    public function test_user_without_update_permission_cannot_update_application(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'job_applications.view');
        $application = RecruitmentApplication::factory()->create(['tenant_id' => $tenant->id]);

        $response = $this->actingAs($user)->patchJson($this->url($tenant, "job-applications/{$application->id}"), ['phone' => '555-1234']);

        $response->assertForbidden();
    }

    // 9: user with update permission can update
    public function test_user_with_update_permission_can_update_application(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'job_applications.update');
        // Applicant explicitly created within the same tenant — the
        // factory's own applicant nesting would otherwise default to a
        // different, randomly-generated tenant (RecruitmentApplicantFactory
        // always sets its own tenant_id), same limitation
        // LifecycleProcessFactory/EmployeeFactory already have.
        $applicant = RecruitmentApplicant::factory()->create(['tenant_id' => $tenant->id]);
        $application = RecruitmentApplication::factory()->create([
            'tenant_id' => $tenant->id,
            'recruitment_applicant_id' => $applicant->id,
        ]);

        $response = $this->actingAs($user)->patchJson($this->url($tenant, "job-applications/{$application->id}"), ['phone' => '555-1234']);

        $response->assertOk();
        $this->assertSame('555-1234', $application->applicant->fresh()->phone);
    }

    public function test_user_without_delete_permission_cannot_archive_application(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'job_applications.view');
        $application = RecruitmentApplication::factory()->create(['tenant_id' => $tenant->id]);

        $response = $this->actingAs($user)->deleteJson($this->url($tenant, "job-applications/{$application->id}"));

        $response->assertForbidden();
    }

    public function test_user_with_delete_permission_can_archive_application(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'job_applications.delete');
        $application = RecruitmentApplication::factory()->create(['tenant_id' => $tenant->id]);

        $response = $this->actingAs($user)->deleteJson($this->url($tenant, "job-applications/{$application->id}"));

        $response->assertOk();
        $this->assertSame('archived', $application->fresh()->status->value);
        $this->assertSoftDeleted('recruitment_applications', ['id' => $application->id]);
    }

    // 10: stage changes require permission
    public function test_user_without_update_stage_permission_cannot_change_stage(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'job_applications.view');
        $application = RecruitmentApplication::factory()->create(['tenant_id' => $tenant->id]);

        $response = $this->actingAs($user)->patchJson($this->url($tenant, "job-applications/{$application->id}/stage"), ['stage' => 'screening']);

        $response->assertForbidden();
    }

    public function test_user_with_update_stage_permission_can_change_stage(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'job_applications.update_stage');
        $application = RecruitmentApplication::factory()->create(['tenant_id' => $tenant->id]);

        $response = $this->actingAs($user)->patchJson($this->url($tenant, "job-applications/{$application->id}/stage"), ['stage' => 'screening']);

        $response->assertOk();
        $this->assertSame('screening', $application->fresh()->stage->value);
    }

    public function test_invalid_stage_transition_is_rejected(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'job_applications.update_stage');
        $application = RecruitmentApplication::factory()->atStage(ApplicationStage::Applied)->create(['tenant_id' => $tenant->id]);

        $response = $this->actingAs($user)->patchJson($this->url($tenant, "job-applications/{$application->id}/stage"), ['stage' => 'hired']);

        $response->assertStatus(422)->assertJsonValidationErrors('stage');
    }

    public function test_rejected_application_cannot_be_marked_ready_for_conversion(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'job_applications.mark_ready_for_conversion');
        $application = RecruitmentApplication::factory()->atStage(ApplicationStage::Rejected)->create(['tenant_id' => $tenant->id]);

        $response = $this->actingAs($user)->patchJson($this->url($tenant, "job-applications/{$application->id}/ready-for-conversion"), ['ready_for_conversion' => true]);

        $response->assertStatus(422)->assertJsonValidationErrors('ready_for_conversion');
    }

    public function test_user_without_mark_ready_permission_cannot_mark_ready(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'job_applications.update_stage');
        $application = RecruitmentApplication::factory()->atStage(ApplicationStage::Offer)->create(['tenant_id' => $tenant->id]);

        $response = $this->actingAs($user)->patchJson($this->url($tenant, "job-applications/{$application->id}/ready-for-conversion"), ['ready_for_conversion' => true]);

        $response->assertForbidden();
    }

    public function test_user_with_mark_ready_permission_can_mark_ready(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'job_applications.mark_ready_for_conversion');
        $application = RecruitmentApplication::factory()->atStage(ApplicationStage::Offer)->create(['tenant_id' => $tenant->id]);

        $response = $this->actingAs($user)->patchJson($this->url($tenant, "job-applications/{$application->id}/ready-for-conversion"), ['ready_for_conversion' => true]);

        $response->assertOk();
        $this->assertTrue($application->fresh()->ready_for_conversion);
    }

    // 11: notes require permission
    public function test_user_without_add_note_permission_cannot_add_note(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'job_applications.view');
        $application = RecruitmentApplication::factory()->create(['tenant_id' => $tenant->id]);

        $response = $this->actingAs($user)->postJson($this->url($tenant, "job-applications/{$application->id}/notes"), ['note' => 'Strong candidate']);

        $response->assertForbidden();
    }

    public function test_user_with_add_note_permission_can_add_note(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'job_applications.add_note');
        $application = RecruitmentApplication::factory()->create(['tenant_id' => $tenant->id]);

        $response = $this->actingAs($user)->postJson($this->url($tenant, "job-applications/{$application->id}/notes"), ['note' => 'Strong candidate']);

        $response->assertCreated();
        $this->assertDatabaseHas('recruitment_application_notes', [
            'tenant_id' => $tenant->id,
            'recruitment_application_id' => $application->id,
            'note' => 'Strong candidate',
            'visibility' => 'internal',
        ]);
    }

    // 12: notes are tenant-scoped
    public function test_note_belongs_to_the_applications_tenant_and_is_isolated(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();
        $userA = $this->userWithPermissions($tenantA, 'job_applications.view', 'job_applications.add_note');
        $applicationB = RecruitmentApplication::factory()->create(['tenant_id' => $tenantB->id]);

        $this->actingAs($userA)->postJson($this->url($tenantA, "job-applications/{$applicationB->id}/notes"), ['note' => 'x'])->assertNotFound();

        $note = RecruitmentApplicationNote::factory()->create(['tenant_id' => $tenantA->id]);
        $this->assertSame($tenantA->id, $note->tenant_id);
    }

    // 4: Tenant A cannot access Tenant B applications
    public function test_tenant_a_cannot_view_update_or_archive_tenant_b_application(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();
        $userA = $this->userWithPermissions($tenantA, 'job_applications.view', 'job_applications.update', 'job_applications.delete');
        $applicationB = RecruitmentApplication::factory()->create(['tenant_id' => $tenantB->id]);

        $this->actingAs($userA)->getJson($this->url($tenantA, "job-applications/{$applicationB->id}"))->assertNotFound();
        $this->actingAs($userA)->patchJson($this->url($tenantA, "job-applications/{$applicationB->id}"), ['phone' => 'x'])->assertNotFound();
        $this->actingAs($userA)->deleteJson($this->url($tenantA, "job-applications/{$applicationB->id}"))->assertNotFound();
    }

    public function test_tenant_a_cannot_list_tenant_b_applications(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();
        $userA = $this->userWithPermissions($tenantA, 'job_applications.view');
        RecruitmentApplication::factory()->create(['tenant_id' => $tenantB->id]);

        $response = $this->actingAs($userA)->getJson($this->url($tenantA, 'job-applications'));

        $response->assertOk();
        $this->assertCount(0, $response->json('data'));
    }

    // 5: Platform Super Admin blocked
    public function test_platform_super_admin_is_blocked_from_job_applications_api(): void
    {
        $tenant = Tenant::factory()->create();
        $admin = User::factory()->platformAdmin()->create();

        $response = $this->actingAs($admin)->getJson($this->url($tenant, 'job-applications'));

        $response->assertForbidden();
    }

    // 13: resource safety — no internal fields exposed, cover_letter is only shown to legitimate viewers, never in audit
    public function test_job_application_resource_does_not_expose_internal_fields(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'job_applications.view');
        RecruitmentApplication::factory()->create(['tenant_id' => $tenant->id]);

        $response = $this->actingAs($user)->getJson($this->url($tenant, 'job-applications'));
        $body = json_encode($response->json());

        $this->assertStringNotContainsString('"tenant_id"', $body);
        $this->assertStringNotContainsString('"created_by"', $body);
        $this->assertStringNotContainsString('"updated_by"', $body);
        $this->assertStringNotContainsString('"deleted_at"', $body);
    }

    // 14: audit logs are written, and never contain the cover letter or note text
    public function test_create_application_writes_audit_log_without_cover_letter(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'job_applications.create');
        $job = RecruitmentJob::factory()->create(['tenant_id' => $tenant->id]);

        $this->actingAs($user)->postJson($this->url($tenant, 'job-applications'), [
            'recruitment_job_id' => $job->id,
            'first_name' => 'Ada',
            'last_name' => 'Lovelace',
            'email' => 'ada@example.com',
            'cover_letter' => 'CONFIDENTIAL_COVER_LETTER_TEXT',
        ])->assertCreated();

        $this->assertDatabaseHas('audit_logs', ['action' => 'job_application.created', 'module' => 'recruitment', 'actor_user_id' => $user->id]);
        $log = AuditLog::query()->where('action', 'job_application.created')->firstOrFail();
        $this->assertStringNotContainsString('CONFIDENTIAL_COVER_LETTER_TEXT', json_encode($log->toArray()));
    }

    public function test_stage_change_writes_audit_log(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'job_applications.update_stage');
        $application = RecruitmentApplication::factory()->create(['tenant_id' => $tenant->id]);

        $this->actingAs($user)->patchJson($this->url($tenant, "job-applications/{$application->id}/stage"), ['stage' => 'screening'])->assertOk();

        $this->assertDatabaseHas('audit_logs', ['action' => 'job_application.stage_changed', 'module' => 'recruitment', 'actor_user_id' => $user->id]);
    }

    public function test_add_note_writes_audit_log_without_note_text(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'job_applications.add_note');
        $application = RecruitmentApplication::factory()->create(['tenant_id' => $tenant->id]);

        $this->actingAs($user)->postJson($this->url($tenant, "job-applications/{$application->id}/notes"), ['note' => 'CONFIDENTIAL_NOTE_TEXT'])->assertCreated();

        $this->assertDatabaseHas('audit_logs', ['action' => 'job_application_note.created', 'module' => 'recruitment', 'actor_user_id' => $user->id]);
        $log = AuditLog::query()->where('action', 'job_application_note.created')->firstOrFail();
        $this->assertStringNotContainsString('CONFIDENTIAL_NOTE_TEXT', json_encode($log->toArray()));
    }

    public function test_archive_application_writes_audit_log(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'job_applications.delete');
        $application = RecruitmentApplication::factory()->create(['tenant_id' => $tenant->id]);

        $this->actingAs($user)->deleteJson($this->url($tenant, "job-applications/{$application->id}"))->assertOk();

        $this->assertDatabaseHas('audit_logs', ['action' => 'job_application.archived', 'module' => 'recruitment', 'actor_user_id' => $user->id]);
    }

    // Verifies a hired application can never be produced without going through valid intermediate stages
    public function test_full_pipeline_can_progress_from_applied_to_hired(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'job_applications.update_stage');
        $application = RecruitmentApplication::factory()->create(['tenant_id' => $tenant->id]);

        foreach (['screening', 'interview', 'offer', 'hired'] as $stage) {
            $this->actingAs($user)->patchJson($this->url($tenant, "job-applications/{$application->id}/stage"), ['stage' => $stage])->assertOk();
        }

        $this->assertSame('hired', $application->fresh()->stage->value);
    }

    public function test_no_hard_delete_ever_removes_the_row(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'job_applications.delete');
        $application = RecruitmentApplication::factory()->create(['tenant_id' => $tenant->id]);

        $this->actingAs($user)->deleteJson($this->url($tenant, "job-applications/{$application->id}"))->assertOk();

        $this->assertDatabaseHas('recruitment_applications', ['id' => $application->id]);
    }

    // All job-applications routes carry tenant.matches
    public function test_all_job_application_routes_include_tenant_matches_middleware(): void
    {
        $uris = [
            'api/v1/job-applications',
            'api/v1/job-applications/{jobApplication}',
            'api/v1/job-applications/{jobApplication}/notes',
            'api/v1/job-applications/{jobApplication}/stage',
            'api/v1/job-applications/{jobApplication}/ready-for-conversion',
            'api/v1/job-applications/{jobApplication}/convert-to-employee',
            'api/v1/job-applications/{jobApplication}/start-onboarding',
        ];

        $routes = collect(Route::getRoutes())->filter(fn ($route) => in_array($route->uri(), $uris));

        $this->assertGreaterThanOrEqual(count($uris), $routes->count());

        foreach ($routes as $route) {
            $this->assertContains(
                'tenant.matches',
                $route->gatherMiddleware(),
                "Route [{$route->methods()[0]} {$route->uri()}] is missing tenant.matches middleware.",
            );
        }
    }
}
