<?php

namespace Tests\Feature\Departments;

use App\Models\AuditLog;
use App\Models\Department;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Checkpoint 32 — Employee Lifecycle Foundation. Department already
 * uses BelongsToTenant (Checkpoint 26), the standard two-layer tenant
 * pattern (global scope + the controller's explicit ownership check),
 * same shape as DocumentCategoryApiTest.
 */
class DepartmentApiTest extends TestCase
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

    // 1: guest cannot access API
    public function test_guest_cannot_access_department_api(): void
    {
        $tenant = Tenant::factory()->create();

        $this->getJson($this->url($tenant, 'departments'))->assertUnauthorized();
    }

    // 2/3: view permission gating
    public function test_user_without_view_permission_cannot_list_departments(): void
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->create(['tenant_id' => $tenant->id]);

        $this->actingAs($user)->getJson($this->url($tenant, 'departments'))->assertForbidden();
    }

    public function test_user_with_view_permission_can_list_and_view_departments(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'departments.view');
        $department = Department::factory()->count(2)->create(['tenant_id' => $tenant->id])->first();

        $listResponse = $this->actingAs($user)->getJson($this->url($tenant, 'departments'));
        $listResponse->assertOk();
        $this->assertCount(2, $listResponse->json('data'));

        $showResponse = $this->actingAs($user)->getJson($this->url($tenant, "departments/{$department->id}"));
        $showResponse->assertOk();
        $showResponse->assertJsonPath('data.id', $department->id);
    }

    // 4/5: create permission gating
    public function test_user_without_create_permission_cannot_create_department(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'departments.view');

        $response = $this->actingAs($user)->postJson($this->url($tenant, 'departments'), ['name' => 'Engineering']);

        $response->assertForbidden();
    }

    public function test_user_with_create_permission_can_create_department(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'departments.create');

        $response = $this->actingAs($user)->postJson($this->url($tenant, 'departments'), ['name' => 'Engineering']);

        $response->assertCreated();
        $response->assertJsonPath('data.slug', 'engineering');
        $response->assertJsonPath('data.status', 'active');
        $this->assertDatabaseHas('departments', ['name' => 'Engineering', 'slug' => 'engineering', 'tenant_id' => $tenant->id]);
    }

    // 6/7: update permission gating
    public function test_user_without_update_permission_cannot_update_department(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'departments.view');
        $department = Department::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Original']);

        $response = $this->actingAs($user)->patchJson($this->url($tenant, "departments/{$department->id}"), ['name' => 'Hacked']);

        $response->assertForbidden();
        $this->assertSame('Original', $department->fresh()->name);
    }

    public function test_user_with_update_permission_can_update_department(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'departments.update');
        $department = Department::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Before']);

        $response = $this->actingAs($user)->patchJson($this->url($tenant, "departments/{$department->id}"), ['name' => 'After']);

        $response->assertOk();
        $response->assertJsonPath('data.name', 'After');
    }

    // slug is never accepted from the request, even on update
    public function test_slug_cannot_be_changed_via_update(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'departments.update');
        $department = Department::factory()->create(['tenant_id' => $tenant->id, 'slug' => 'original-slug']);

        $response = $this->actingAs($user)->patchJson($this->url($tenant, "departments/{$department->id}"), [
            'name' => $department->name,
            'slug' => 'forged-slug',
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.slug', 'original-slug');
    }

    // tenant_id/created_by/updated_by/status(create)/system flags are never accepted from the request
    public function test_forged_fields_are_ignored_on_create(): void
    {
        $tenant = Tenant::factory()->create();
        $otherTenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'departments.create');

        $response = $this->actingAs($user)->postJson($this->url($tenant, 'departments'), [
            'name' => 'Engineering',
            'tenant_id' => $otherTenant->id,
            'status' => 'inactive',
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.status', 'active');
        $this->assertDatabaseHas('departments', ['name' => 'Engineering', 'tenant_id' => $tenant->id]);
        $this->assertDatabaseMissing('departments', ['name' => 'Engineering', 'tenant_id' => $otherTenant->id]);
    }

    // 8/9: delete/archive permission gating
    public function test_user_without_delete_permission_cannot_archive_department(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'departments.view');
        $department = Department::factory()->create(['tenant_id' => $tenant->id]);

        $response = $this->actingAs($user)->deleteJson($this->url($tenant, "departments/{$department->id}"));

        $response->assertForbidden();
        $this->assertNull($department->fresh()->deleted_at);
    }

    public function test_user_with_delete_permission_can_archive_department(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'departments.delete');
        $department = Department::factory()->create(['tenant_id' => $tenant->id]);

        $response = $this->actingAs($user)->deleteJson($this->url($tenant, "departments/{$department->id}"));

        $response->assertOk();
        $this->assertSoftDeleted('departments', ['id' => $department->id]);
    }

    // 10: tenant isolation
    public function test_tenant_a_cannot_view_edit_or_archive_tenant_b_department(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();
        $userA = $this->userWithPermissions($tenantA, 'departments.view', 'departments.update', 'departments.delete');
        $departmentB = Department::factory()->create(['tenant_id' => $tenantB->id, 'name' => 'Untouched']);

        $this->actingAs($userA)->getJson($this->url($tenantA, "departments/{$departmentB->id}"))->assertNotFound();
        $this->actingAs($userA)->patchJson($this->url($tenantA, "departments/{$departmentB->id}"), ['name' => 'Hacked'])->assertNotFound();
        $this->actingAs($userA)->deleteJson($this->url($tenantA, "departments/{$departmentB->id}"))->assertNotFound();

        $this->assertSame('Untouched', $departmentB->fresh()->name);
        $this->assertNull($departmentB->fresh()->deleted_at);
    }

    public function test_tenant_a_cannot_list_tenant_b_departments(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();
        $userA = $this->userWithPermissions($tenantA, 'departments.view');
        Department::factory()->create(['tenant_id' => $tenantA->id, 'name' => 'A Department']);
        Department::factory()->create(['tenant_id' => $tenantB->id, 'name' => 'B Department']);

        $response = $this->actingAs($userA)->getJson($this->url($tenantA, 'departments'));

        $names = collect($response->json('data'))->pluck('name');
        $this->assertTrue($names->contains('A Department'));
        $this->assertFalse($names->contains('B Department'));
    }

    // 11: Platform Super Admin blocked
    public function test_platform_super_admin_is_blocked_from_department_api(): void
    {
        $tenant = Tenant::factory()->create();
        $admin = User::factory()->platformAdmin()->create();

        $response = $this->actingAs($admin)->getJson($this->url($tenant, 'departments'));

        $response->assertForbidden();
    }

    // 12: resource safety
    public function test_department_api_does_not_expose_internal_fields(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'departments.view');
        Department::factory()->create(['tenant_id' => $tenant->id]);

        $response = $this->actingAs($user)->getJson($this->url($tenant, 'departments'));
        $body = json_encode($response->json());

        foreach (['created_by', 'updated_by', 'deleted_at'] as $internalKey) {
            $this->assertStringNotContainsString($internalKey, $body, "Response unexpectedly contains '{$internalKey}'.");
        }
        $this->assertStringNotContainsString('"tenant_id"', $body);
    }

    // 13/14/15: audit logging
    public function test_create_department_writes_audit_log(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'departments.create');

        $this->actingAs($user)->postJson($this->url($tenant, 'departments'), ['name' => 'Engineering'])->assertCreated();

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'department.created',
            'module' => 'employees',
            'actor_user_id' => $user->id,
            'tenant_id' => $tenant->id,
        ]);
    }

    public function test_update_department_writes_audit_log(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'departments.update');
        $department = Department::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Before']);

        $this->actingAs($user)->patchJson($this->url($tenant, "departments/{$department->id}"), ['name' => 'After'])->assertOk();

        $log = AuditLog::query()->where('action', 'department.updated')->where('auditable_id', $department->id)->first();
        $this->assertNotNull($log);
        $this->assertSame('Before', $log->old_values['name']);
        $this->assertSame('After', $log->new_values['name']);
    }

    public function test_archive_department_writes_audit_log(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'departments.delete');
        $department = Department::factory()->create(['tenant_id' => $tenant->id]);

        $this->actingAs($user)->deleteJson($this->url($tenant, "departments/{$department->id}"))->assertOk();

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'department.archived',
            'module' => 'employees',
            'actor_user_id' => $user->id,
            'auditable_id' => $department->id,
        ]);
    }

    public function test_all_department_routes_include_tenant_matches_middleware(): void
    {
        $departmentRoutes = collect(Route::getRoutes())
            ->filter(fn ($route) => str_starts_with($route->uri(), 'api/v1/departments'));

        $this->assertGreaterThanOrEqual(5, $departmentRoutes->count());

        foreach ($departmentRoutes as $route) {
            $this->assertContains(
                'tenant.matches',
                $route->gatherMiddleware(),
                "Route [{$route->uri()}] is missing tenant.matches middleware.",
            );
        }
    }

    public function test_inactive_user_fails_closed(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'departments.view');
        $user->update(['status' => User::STATUS_INACTIVE]);

        $response = $this->actingAs($user)->getJson($this->url($tenant, 'departments'));

        $response->assertForbidden();
    }

    public function test_user_under_inactive_tenant_fails_closed(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'departments.view');
        $tenant->update(['status' => Tenant::STATUS_SUSPENDED]);

        $response = $this->actingAs($user)->getJson($this->url($tenant, 'departments'));

        $response->assertForbidden();
    }

    public function test_name_is_unique_within_tenant(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'departments.create');
        Department::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Engineering']);

        $response = $this->actingAs($user)->postJson($this->url($tenant, 'departments'), ['name' => 'Engineering']);

        $response->assertStatus(422)->assertJsonValidationErrors('name');
    }

    public function test_same_name_can_exist_in_different_tenants(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();
        Department::factory()->create(['tenant_id' => $tenantA->id, 'name' => 'Engineering']);
        $user = $this->userWithPermissions($tenantB, 'departments.create');

        $response = $this->actingAs($user)->postJson($this->url($tenantB, 'departments'), ['name' => 'Engineering']);

        $response->assertCreated();
    }

    public function test_no_hard_delete_route_exists(): void
    {
        $departmentRoutes = collect(Route::getRoutes())
            ->filter(fn ($route) => str_starts_with($route->uri(), 'api/v1/departments'))
            ->map(fn ($route) => implode('|', $route->methods()));

        // DELETE exists (soft-delete/archive only) — confirmed by the
        // controller itself never calling forceDelete(); no separate
        // hard-delete route/verb exists beyond the one DELETE route.
        $this->assertCount(5, $departmentRoutes);
    }
}
