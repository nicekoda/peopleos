<?php

namespace Tests\Feature\Tenant;

use App\Enums\TenantModule;
use App\Models\AuditLog;
use App\Models\Permission;
use App\Models\RecruitmentApplication;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\TenantModuleAssignment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class TenantModuleApiTest extends TestCase
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

    // 1: Tenant Admin can enable/disable toggleable modules
    public function test_user_with_manage_permission_can_disable_and_reenable_a_module(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'tenant.modules.view', 'tenant.modules.manage');

        $disable = $this->actingAs($user)->patchJson($this->url($tenant, 'tenant/modules/recruitment'), ['enabled' => false]);
        $disable->assertOk();
        $disable->assertJsonPath('data.enabled', false);

        // 11: re-enabled module works again
        $reenable = $this->actingAs($user)->patchJson($this->url($tenant, 'tenant/modules/recruitment'), ['enabled' => true]);
        $reenable->assertOk();
        $reenable->assertJsonPath('data.enabled', true);
    }

    // 2: HR Manager/HR Director can view but not manage
    public function test_view_only_permission_can_list_but_cannot_manage(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'tenant.modules.view');

        $this->actingAs($user)->getJson($this->url($tenant, 'tenant/modules'))->assertOk();
        $this->actingAs($user)->patchJson($this->url($tenant, 'tenant/modules/recruitment'), ['enabled' => false])->assertForbidden();
    }

    // 4: HR Officer (no grant at all) cannot view or manage
    public function test_user_without_any_tenant_module_permission_is_blocked(): void
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->create(['tenant_id' => $tenant->id]);

        $this->actingAs($user)->getJson($this->url($tenant, 'tenant/modules'))->assertForbidden();
        $this->actingAs($user)->patchJson($this->url($tenant, 'tenant/modules/recruitment'), ['enabled' => false])->assertForbidden();
    }

    // 5: Tenant A cannot manage Tenant B modules
    public function test_tenant_a_cannot_manage_tenant_b_modules(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();
        $userA = $this->userWithPermissions($tenantA, 'tenant.modules.view', 'tenant.modules.manage');

        // Disabling recruitment for tenant A must never affect tenant B —
        // proven by disabling on A, then checking B's own state via B's
        // own session.
        $this->actingAs($userA)->patchJson($this->url($tenantA, 'tenant/modules/recruitment'), ['enabled' => false])->assertOk();

        $userB = $this->userWithPermissions($tenantB, 'tenant.modules.view');
        $responseB = $this->actingAs($userB)->getJson($this->url($tenantB, 'tenant/modules'));
        $responseB->assertOk();
        $recruitmentB = collect($responseB->json('data'))->firstWhere('module_key', 'recruitment');
        $this->assertTrue($recruitmentB['enabled']);
    }

    // 6: Platform Super Admin behaviour — verified directly, not assumed
    public function test_platform_super_admin_cannot_reach_tenant_module_routes_at_all(): void
    {
        $tenant = Tenant::factory()->create();
        $admin = User::factory()->platformAdmin()->create();

        // Blocked by tenant.matches itself (resolvedTenant !== null for a
        // platform-admin session is never a match) — before permission
        // or module-gate middleware is ever evaluated. See
        // EnsureTenantMatchesAuthenticatedUser.
        $this->actingAs($admin)->getJson($this->url($tenant, 'tenant/modules'))->assertForbidden();
        $this->actingAs($admin)->patchJson($this->url($tenant, 'tenant/modules/recruitment'), ['enabled' => false])->assertForbidden();
    }

    // 6 (continued): a platform admin cannot use a disabled module as a
    // route into tenant data either — same block applies regardless of
    // module state, proving the module gate is never what stands between
    // a platform admin and tenant data (tenant.matches already is).
    public function test_platform_super_admin_cannot_bypass_a_disabled_module_via_tenant_routes(): void
    {
        $tenant = Tenant::factory()->create();
        $admin = User::factory()->platformAdmin()->create();
        $user = $this->userWithPermissions($tenant, 'tenant.modules.manage', 'job_openings.view');
        $this->actingAs($user)->patchJson($this->url($tenant, 'tenant/modules/recruitment'), ['enabled' => false])->assertOk();

        $response = $this->actingAs($admin)->getJson($this->url($tenant, 'job-openings'));

        $response->assertForbidden();
        $this->assertStringNotContainsString('module_disabled', (string) $response->getContent());
    }

    // 9/10: disabled module blocks the API route with 403/module_disabled
    public function test_disabled_module_blocks_its_api_routes_with_module_disabled_reason(): void
    {
        $tenant = Tenant::factory()->create();
        $admin = $this->userWithPermissions($tenant, 'tenant.modules.manage');
        $viewer = $this->userWithPermissions($tenant, 'job_openings.view');

        $this->actingAs($admin)->patchJson($this->url($tenant, 'tenant/modules/recruitment'), ['enabled' => false])->assertOk();

        $response = $this->actingAs($viewer)->getJson($this->url($tenant, 'job-openings'));

        $response->assertForbidden();
        $response->assertJson(['message' => 'This module is not enabled for your organisation.', 'reason' => 'module_disabled']);
    }

    // 12: disabling a module preserves its data
    public function test_disabling_a_module_never_deletes_its_data(): void
    {
        $tenant = Tenant::factory()->create();
        $admin = $this->userWithPermissions($tenant, 'tenant.modules.manage');
        $application = RecruitmentApplication::factory()->create(['tenant_id' => $tenant->id]);

        $this->actingAs($admin)->patchJson($this->url($tenant, 'tenant/modules/recruitment'), ['enabled' => false])->assertOk();

        $this->assertDatabaseHas('recruitment_applications', ['id' => $application->id, 'deleted_at' => null]);
    }

    // 13: core modules cannot be disabled
    public function test_core_module_key_is_rejected_with_422(): void
    {
        $tenant = Tenant::factory()->create();
        $admin = $this->userWithPermissions($tenant, 'tenant.modules.manage');

        $response = $this->actingAs($admin)->patchJson($this->url($tenant, 'tenant/modules/employees'), ['enabled' => false]);

        $response->assertStatus(422);
    }

    // 14: unknown module keys are rejected — 422, never a route-binding 404
    public function test_unknown_module_key_is_rejected_with_422(): void
    {
        $tenant = Tenant::factory()->create();
        $admin = $this->userWithPermissions($tenant, 'tenant.modules.manage');

        $response = $this->actingAs($admin)->patchJson($this->url($tenant, 'tenant/modules/not-a-real-module'), ['enabled' => false]);

        $response->assertStatus(422);
    }

    // 20: audit logs are written safely, with before/after values
    public function test_disabling_a_module_writes_a_safe_audit_log(): void
    {
        $tenant = Tenant::factory()->create();
        $admin = $this->userWithPermissions($tenant, 'tenant.modules.manage');

        $this->actingAs($admin)->patchJson($this->url($tenant, 'tenant/modules/recruitment'), ['enabled' => false])->assertOk();

        $this->assertDatabaseHas('audit_logs', ['action' => 'module.disabled', 'module' => 'settings', 'actor_user_id' => $admin->id]);
        $log = AuditLog::query()->where('action', 'module.disabled')->firstOrFail();
        $this->assertSame('recruitment', $log->metadata['module_key']);
        $this->assertTrue($log->metadata['previous_enabled']);
        $this->assertFalse($log->metadata['new_enabled']);
    }

    public function test_enabling_a_module_writes_a_safe_audit_log(): void
    {
        $tenant = Tenant::factory()->create();
        $admin = $this->userWithPermissions($tenant, 'tenant.modules.manage');
        TenantModuleAssignment::query()->updateOrCreate(
            ['tenant_id' => $tenant->id, 'module_key' => TenantModule::Leave->value],
            ['enabled' => false],
        );

        $this->actingAs($admin)->patchJson($this->url($tenant, 'tenant/modules/leave'), ['enabled' => true])->assertOk();

        $this->assertDatabaseHas('audit_logs', ['action' => 'module.enabled', 'module' => 'settings', 'actor_user_id' => $admin->id]);
    }

    // Resource safety — view-only caller never sees row IDs/actor IDs/timestamps
    public function test_module_list_never_exposes_internal_fields(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'tenant.modules.view');

        $response = $this->actingAs($user)->getJson($this->url($tenant, 'tenant/modules'));

        $response->assertOk();
        $body = json_encode($response->json());
        $this->assertStringNotContainsString('"id"', $body);
        $this->assertStringNotContainsString('"enabled_by"', $body);
        $this->assertStringNotContainsString('"disabled_by"', $body);
        $this->assertStringNotContainsString('"created_at"', $body);
        $this->assertStringNotContainsString('"tenant_id"', $body);
    }

    // Missing row fails open as a fallback only
    public function test_missing_tenant_module_row_defaults_to_enabled(): void
    {
        $tenant = Tenant::factory()->create();
        TenantModuleAssignment::query()->where('tenant_id', $tenant->id)->delete();
        $user = $this->userWithPermissions($tenant, 'tenant.modules.view', 'job_openings.view');

        $response = $this->actingAs($user)->getJson($this->url($tenant, 'job-openings'));

        $response->assertOk();
    }

    // 15 (module-gate side): recruitment-to-onboarding handoff blocked when
    // either Recruitment or Lifecycle is disabled — both module gates
    // present on the same route.
    public function test_start_onboarding_route_is_gated_by_both_recruitment_and_lifecycle_modules(): void
    {
        $uri = 'api/v1/job-applications/{jobApplication}/start-onboarding';
        $route = collect(Route::getRoutes())->first(fn ($r) => $r->uri() === $uri);

        $this->assertNotNull($route);
        $this->assertContains('module:recruitment', $route->gatherMiddleware());
        $this->assertContains('module:lifecycle', $route->gatherMiddleware());
    }

    // 22: existing tenant isolation remains enforced
    public function test_inactive_user_fails_closed(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'tenant.modules.view');
        $user->update(['status' => User::STATUS_INACTIVE]);

        $response = $this->actingAs($user)->getJson($this->url($tenant, 'tenant/modules'));

        $response->assertForbidden();
    }
}
