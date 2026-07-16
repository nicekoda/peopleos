<?php

namespace Tests\Feature\Recruitment;

use App\Models\Department;
use App\Models\Permission;
use App\Models\RecruitmentJob;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class JobOpeningApiTest extends TestCase
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

    // 1: guest cannot access job-openings API
    public function test_guest_cannot_access_job_openings_api(): void
    {
        $tenant = Tenant::factory()->create();
        $job = RecruitmentJob::factory()->create(['tenant_id' => $tenant->id]);

        $this->getJson($this->url($tenant, 'job-openings'))->assertUnauthorized();
        $this->postJson($this->url($tenant, 'job-openings'), [])->assertUnauthorized();
        $this->getJson($this->url($tenant, "job-openings/{$job->id}"))->assertUnauthorized();
        $this->patchJson($this->url($tenant, "job-openings/{$job->id}"), [])->assertUnauthorized();
        $this->deleteJson($this->url($tenant, "job-openings/{$job->id}"))->assertUnauthorized();
    }

    // 2: user without job_openings.view cannot list/view
    public function test_user_without_view_permission_cannot_list_or_view(): void
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        $job = RecruitmentJob::factory()->create(['tenant_id' => $tenant->id]);

        $this->actingAs($user)->getJson($this->url($tenant, 'job-openings'))->assertForbidden();
        $this->actingAs($user)->getJson($this->url($tenant, "job-openings/{$job->id}"))->assertForbidden();
    }

    // 3: user with permission can list/view same-tenant records
    public function test_user_with_view_permission_can_list_and_view(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'job_openings.view');
        $job = RecruitmentJob::factory()->create(['tenant_id' => $tenant->id]);

        $this->actingAs($user)->getJson($this->url($tenant, 'job-openings'))->assertOk();
        $this->actingAs($user)->getJson($this->url($tenant, "job-openings/{$job->id}"))->assertOk();
    }

    // Checkpoint 47 — disabling the Recruitment module blocks its API
    // even for a user who otherwise holds job_openings.view.
    public function test_disabled_recruitment_module_blocks_the_api_with_module_disabled_reason(): void
    {
        $tenant = Tenant::factory()->create();
        $admin = $this->userWithPermissions($tenant, 'tenant.modules.manage');
        $user = $this->userWithPermissions($tenant, 'job_openings.view');

        $this->actingAs($admin)->patchJson($this->url($tenant, 'tenant/modules/recruitment'), ['enabled' => false])->assertOk();

        $response = $this->actingAs($user)->getJson($this->url($tenant, 'job-openings'));

        $response->assertForbidden();
        $response->assertJsonPath('reason', 'module_disabled');
    }

    // 6: user without create permission cannot create
    public function test_user_without_create_permission_cannot_create_job(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'job_openings.view');

        $response = $this->actingAs($user)->postJson($this->url($tenant, 'job-openings'), ['title' => 'Backend Engineer']);

        $response->assertForbidden();
    }

    // 7: user with create permission can create
    public function test_user_with_create_permission_can_create_job(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'job_openings.create');

        $response = $this->actingAs($user)->postJson($this->url($tenant, 'job-openings'), ['title' => 'Backend Engineer']);

        $response->assertCreated();
        $this->assertDatabaseHas('recruitment_jobs', [
            'tenant_id' => $tenant->id,
            'title' => 'Backend Engineer',
            'status' => 'draft',
        ]);
    }

    // Forged system fields cannot be set via create
    public function test_forged_system_fields_are_ignored_on_create(): void
    {
        $tenant = Tenant::factory()->create();
        $otherTenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'job_openings.create');
        $otherUser = User::factory()->create(['tenant_id' => $otherTenant->id]);

        $response = $this->actingAs($user)->postJson($this->url($tenant, 'job-openings'), [
            'title' => 'Backend Engineer',
            'tenant_id' => $otherTenant->id,
            'status' => 'open',
            'created_by' => $otherUser->id,
        ]);

        $response->assertCreated();
        $job = RecruitmentJob::query()->findOrFail($response->json('data.id'));
        $this->assertSame($tenant->id, $job->tenant_id);
        $this->assertSame('draft', $job->status->value);
        $this->assertSame($user->id, $job->created_by);
    }

    // Department/position/location must belong to the same tenant
    public function test_cannot_create_job_with_another_tenants_department(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenantA, 'job_openings.create');
        $departmentB = Department::factory()->create(['tenant_id' => $tenantB->id]);

        $response = $this->actingAs($user)->postJson($this->url($tenantA, 'job-openings'), [
            'title' => 'Backend Engineer',
            'department_id' => $departmentB->id,
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('department_id');
    }

    // 8: user without update permission cannot update
    public function test_user_without_update_permission_cannot_update_job(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'job_openings.view');
        $job = RecruitmentJob::factory()->create(['tenant_id' => $tenant->id]);

        $response = $this->actingAs($user)->patchJson($this->url($tenant, "job-openings/{$job->id}"), ['title' => 'Updated']);

        $response->assertForbidden();
    }

    // 9: user with update permission can update
    public function test_user_with_update_permission_can_update_job(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'job_openings.update');
        $job = RecruitmentJob::factory()->create(['tenant_id' => $tenant->id]);

        $response = $this->actingAs($user)->patchJson($this->url($tenant, "job-openings/{$job->id}"), ['status' => 'open']);

        $response->assertOk();
        $job->refresh();
        $this->assertSame('open', $job->status->value);
        $this->assertNotNull($job->opened_at);
    }

    public function test_invalid_status_transition_is_rejected(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'job_openings.update');
        $job = RecruitmentJob::factory()->create(['tenant_id' => $tenant->id]);

        $response = $this->actingAs($user)->patchJson($this->url($tenant, "job-openings/{$job->id}"), ['status' => 'closed']);

        $response->assertStatus(422)->assertJsonValidationErrors('status');
    }

    public function test_user_without_delete_permission_cannot_archive_job(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'job_openings.view');
        $job = RecruitmentJob::factory()->create(['tenant_id' => $tenant->id]);

        $response = $this->actingAs($user)->deleteJson($this->url($tenant, "job-openings/{$job->id}"));

        $response->assertForbidden();
    }

    public function test_user_with_delete_permission_can_archive_job(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'job_openings.delete');
        $job = RecruitmentJob::factory()->create(['tenant_id' => $tenant->id]);

        $response = $this->actingAs($user)->deleteJson($this->url($tenant, "job-openings/{$job->id}"));

        $response->assertOk();
        $this->assertSame('cancelled', $job->fresh()->status->value);
        $this->assertSoftDeleted('recruitment_jobs', ['id' => $job->id]);
    }

    // 4: Tenant A cannot access Tenant B jobs
    public function test_tenant_a_cannot_view_update_or_archive_tenant_b_job(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();
        $userA = $this->userWithPermissions($tenantA, 'job_openings.view', 'job_openings.update', 'job_openings.delete');
        $jobB = RecruitmentJob::factory()->create(['tenant_id' => $tenantB->id]);

        $this->actingAs($userA)->getJson($this->url($tenantA, "job-openings/{$jobB->id}"))->assertNotFound();
        $this->actingAs($userA)->patchJson($this->url($tenantA, "job-openings/{$jobB->id}"), ['title' => 'x'])->assertNotFound();
        $this->actingAs($userA)->deleteJson($this->url($tenantA, "job-openings/{$jobB->id}"))->assertNotFound();
    }

    public function test_tenant_a_cannot_list_tenant_b_jobs(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();
        $userA = $this->userWithPermissions($tenantA, 'job_openings.view');
        RecruitmentJob::factory()->create(['tenant_id' => $tenantB->id]);

        $response = $this->actingAs($userA)->getJson($this->url($tenantA, 'job-openings'));

        $response->assertOk();
        $this->assertCount(0, $response->json('data'));
    }

    // 5: Platform Super Admin blocked
    public function test_platform_super_admin_is_blocked_from_job_openings_api(): void
    {
        $tenant = Tenant::factory()->create();
        $admin = User::factory()->platformAdmin()->create();

        $response = $this->actingAs($admin)->getJson($this->url($tenant, 'job-openings'));

        $response->assertForbidden();
    }

    // 13: resource safety — no internal fields exposed
    public function test_job_opening_resource_does_not_expose_internal_fields(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'job_openings.view');
        RecruitmentJob::factory()->create(['tenant_id' => $tenant->id]);

        $response = $this->actingAs($user)->getJson($this->url($tenant, 'job-openings'));
        $body = json_encode($response->json());

        $this->assertStringNotContainsString('"tenant_id"', $body);
        $this->assertStringNotContainsString('"created_by"', $body);
        $this->assertStringNotContainsString('"updated_by"', $body);
        $this->assertStringNotContainsString('"deleted_at"', $body);
    }

    // 14: audit logs are written
    public function test_create_job_writes_audit_log(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'job_openings.create');

        $this->actingAs($user)->postJson($this->url($tenant, 'job-openings'), ['title' => 'Backend Engineer'])->assertCreated();

        $this->assertDatabaseHas('audit_logs', ['action' => 'job_opening.created', 'module' => 'recruitment', 'actor_user_id' => $user->id]);
    }

    public function test_update_job_writes_audit_log(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'job_openings.update');
        $job = RecruitmentJob::factory()->create(['tenant_id' => $tenant->id]);

        $this->actingAs($user)->patchJson($this->url($tenant, "job-openings/{$job->id}"), ['status' => 'open'])->assertOk();

        $this->assertDatabaseHas('audit_logs', ['action' => 'job_opening.updated', 'module' => 'recruitment', 'actor_user_id' => $user->id]);
    }

    public function test_archive_job_writes_audit_log(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'job_openings.delete');
        $job = RecruitmentJob::factory()->create(['tenant_id' => $tenant->id]);

        $this->actingAs($user)->deleteJson($this->url($tenant, "job-openings/{$job->id}"))->assertOk();

        $this->assertDatabaseHas('audit_logs', ['action' => 'job_opening.archived', 'module' => 'recruitment', 'actor_user_id' => $user->id]);
    }

    // Inactive user / inactive tenant fail closed
    public function test_inactive_user_fails_closed(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'job_openings.view');
        $user->update(['status' => User::STATUS_INACTIVE]);

        $response = $this->actingAs($user)->getJson($this->url($tenant, 'job-openings'));

        $response->assertForbidden();
    }

    public function test_user_under_inactive_tenant_fails_closed(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'job_openings.view');
        $tenant->update(['status' => Tenant::STATUS_SUSPENDED]);

        $response = $this->actingAs($user)->getJson($this->url($tenant, 'job-openings'));

        $response->assertForbidden();
    }

    // No hard-delete route exists for job openings
    public function test_no_hard_delete_ever_removes_the_row(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'job_openings.delete');
        $job = RecruitmentJob::factory()->create(['tenant_id' => $tenant->id]);

        $this->actingAs($user)->deleteJson($this->url($tenant, "job-openings/{$job->id}"))->assertOk();

        $this->assertDatabaseHas('recruitment_jobs', ['id' => $job->id]);
    }

    // All job-openings routes carry tenant.matches
    public function test_all_job_opening_routes_include_tenant_matches_middleware(): void
    {
        $uris = [
            'api/v1/job-openings',
            'api/v1/job-openings/{jobOpening}',
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
