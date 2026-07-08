<?php

namespace Tests\Feature\Recruitment;

use App\Models\Permission;
use App\Models\RecruitmentApplication;
use App\Models\RecruitmentJob;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Checkpoint 39 — /recruitment(/jobs)(/jobs/create)(/jobs/{id}/edit)
 * (/applications)(/applications/create)(/applications/{id}). Same shape
 * as every other module UI test — permission gating, guest redirects,
 * tenant isolation, and IDs-only props for the edit/show pages.
 */
class RecruitmentUiTest extends TestCase
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
        return 'http://'.$tenant->subdomain.'.'.config('tenancy.base_domain').'/'.$path;
    }

    public function test_guest_cannot_access_recruitment_ui(): void
    {
        $tenant = Tenant::factory()->create();
        $job = RecruitmentJob::factory()->create(['tenant_id' => $tenant->id]);
        $application = RecruitmentApplication::factory()->create(['tenant_id' => $tenant->id]);

        foreach ([
            'recruitment',
            'recruitment/jobs',
            'recruitment/jobs/create',
            "recruitment/jobs/{$job->id}/edit",
            'recruitment/applications',
            'recruitment/applications/create',
            "recruitment/applications/{$application->id}",
        ] as $path) {
            $this->get($this->url($tenant, $path))->assertRedirect(route('login'));
        }
    }

    public function test_user_without_job_openings_view_cannot_access_jobs_index(): void
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->create(['tenant_id' => $tenant->id]);

        $this->actingAs($user)->get($this->url($tenant, 'recruitment/jobs'))->assertForbidden();
    }

    public function test_user_with_job_openings_view_can_access_jobs_index(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'job_openings.view');

        $response = $this->actingAs($user)->get($this->url($tenant, 'recruitment/jobs'));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page->component('Recruitment/JobsIndex'));
    }

    public function test_user_without_job_openings_create_cannot_access_jobs_create_page(): void
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->create(['tenant_id' => $tenant->id]);

        $this->actingAs($user)->get($this->url($tenant, 'recruitment/jobs/create'))->assertForbidden();
    }

    public function test_user_with_job_openings_create_can_access_jobs_create_page(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'job_openings.create');

        $response = $this->actingAs($user)->get($this->url($tenant, 'recruitment/jobs/create'));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page->component('Recruitment/JobCreate'));
    }

    public function test_user_with_job_openings_update_can_access_jobs_edit_page(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'job_openings.update');
        $job = RecruitmentJob::factory()->create(['tenant_id' => $tenant->id]);

        $response = $this->actingAs($user)->get($this->url($tenant, "recruitment/jobs/{$job->id}/edit"));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page->component('Recruitment/JobEdit')->where('jobId', $job->id));
    }

    public function test_cross_tenant_job_id_returns_404_on_edit_page(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();
        $userA = $this->userWithPermissions($tenantA, 'job_openings.update');
        $jobB = RecruitmentJob::factory()->create(['tenant_id' => $tenantB->id]);

        $this->actingAs($userA)->get($this->url($tenantA, "recruitment/jobs/{$jobB->id}/edit"))->assertNotFound();
    }

    public function test_user_without_job_applications_view_cannot_access_applications_index(): void
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->create(['tenant_id' => $tenant->id]);

        $this->actingAs($user)->get($this->url($tenant, 'recruitment/applications'))->assertForbidden();
    }

    public function test_user_with_job_applications_view_can_access_applications_index(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'job_applications.view');

        $response = $this->actingAs($user)->get($this->url($tenant, 'recruitment/applications'));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page->component('Recruitment/ApplicationsIndex'));
    }

    public function test_user_with_job_applications_view_can_access_application_show_page(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'job_applications.view');
        $application = RecruitmentApplication::factory()->create(['tenant_id' => $tenant->id]);

        $response = $this->actingAs($user)->get($this->url($tenant, "recruitment/applications/{$application->id}"));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page->component('Recruitment/ApplicationShow')->where('applicationId', $application->id));
    }

    public function test_cross_tenant_application_id_returns_404_on_show_page(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();
        $userA = $this->userWithPermissions($tenantA, 'job_applications.view');
        $applicationB = RecruitmentApplication::factory()->create(['tenant_id' => $tenantB->id]);

        $this->actingAs($userA)->get($this->url($tenantA, "recruitment/applications/{$applicationB->id}"))->assertNotFound();
    }

    public function test_edit_page_props_contain_only_job_id(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'job_openings.update');
        $job = RecruitmentJob::factory()->create(['tenant_id' => $tenant->id, 'title' => 'Confidential Role']);

        $response = $this->actingAs($user)->get($this->url($tenant, "recruitment/jobs/{$job->id}/edit"));

        $page = $response->viewData('page');
        $this->assertSame(
            ['jobId'],
            array_keys(array_diff_key($page['props'], ['errors' => 1, 'auth' => 1, 'tenant' => 1])),
        );
        $this->assertStringNotContainsString('Confidential', json_encode($page['props']));
    }

    public function test_show_page_props_contain_only_application_id(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'job_applications.view');
        $application = RecruitmentApplication::factory()->create(['tenant_id' => $tenant->id]);

        $response = $this->actingAs($user)->get($this->url($tenant, "recruitment/applications/{$application->id}"));

        $page = $response->viewData('page');
        $this->assertSame(
            ['applicationId'],
            array_keys(array_diff_key($page['props'], ['errors' => 1, 'auth' => 1, 'tenant' => 1])),
        );
    }
}
