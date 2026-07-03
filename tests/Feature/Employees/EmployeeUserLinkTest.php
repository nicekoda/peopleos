<?php

namespace Tests\Feature\Employees;

use App\Enums\PolicyStatus;
use App\Models\Employee;
use App\Models\Permission;
use App\Models\Policy;
use App\Models\PolicyVersion;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class EmployeeUserLinkTest extends TestCase
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

    public function test_tenant_admin_can_link_same_tenant_user_to_same_tenant_employee(): void
    {
        $tenant = Tenant::factory()->create();
        $admin = $this->userWithPermissions($tenant, 'employees.link_user');
        $targetUser = User::factory()->create(['tenant_id' => $tenant->id]);
        $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);

        $response = $this->actingAs($admin)
            ->postJson($this->url($tenant, "employees/{$employee->id}/link-user"), ['user_id' => $targetUser->id]);

        $response->assertOk();
        $employee->refresh();
        $this->assertSame($targetUser->id, $employee->user_id);
        $this->assertNotNull($employee->linked_at);
        $this->assertSame($admin->id, $employee->linked_by);
    }

    public function test_user_without_permission_cannot_link_user_to_employee(): void
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        $targetUser = User::factory()->create(['tenant_id' => $tenant->id]);
        $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);

        $response = $this->actingAs($user)
            ->postJson($this->url($tenant, "employees/{$employee->id}/link-user"), ['user_id' => $targetUser->id]);

        $response->assertForbidden();
        $this->assertNull($employee->fresh()->user_id);
    }

    public function test_employee_role_cannot_link_user_to_employee(): void
    {
        $tenant = Tenant::factory()->create();
        // Employee role's actual permission set — deliberately not
        // employees.link_user (see RoleSeeder / docs/security.md).
        $employeeUser = $this->userWithPermissions($tenant, 'employees.view', 'policies.acknowledge');
        $targetUser = User::factory()->create(['tenant_id' => $tenant->id]);
        $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);

        $response = $this->actingAs($employeeUser)
            ->postJson($this->url($tenant, "employees/{$employee->id}/link-user"), ['user_id' => $targetUser->id]);

        $response->assertForbidden();
    }

    public function test_tenant_a_admin_cannot_link_tenant_b_user_to_tenant_a_employee(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();
        $admin = $this->userWithPermissions($tenantA, 'employees.link_user');
        $userB = User::factory()->create(['tenant_id' => $tenantB->id]);
        $employeeA = Employee::factory()->create(['tenant_id' => $tenantA->id]);

        $response = $this->actingAs($admin)
            ->postJson($this->url($tenantA, "employees/{$employeeA->id}/link-user"), ['user_id' => $userB->id]);

        $response->assertStatus(422)->assertJsonValidationErrors('user_id');
        $this->assertNull($employeeA->fresh()->user_id);
    }

    public function test_tenant_a_admin_cannot_link_tenant_a_user_to_tenant_b_employee(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();
        $admin = $this->userWithPermissions($tenantA, 'employees.link_user');
        $userA = User::factory()->create(['tenant_id' => $tenantA->id]);
        $employeeB = Employee::factory()->create(['tenant_id' => $tenantB->id]);

        $response = $this->actingAs($admin)
            ->postJson($this->url($tenantA, "employees/{$employeeB->id}/link-user"), ['user_id' => $userA->id]);

        // 404 — the employee itself isn't reachable from tenant A's
        // subdomain at all (defense in depth, before user_id is even
        // validated).
        $response->assertNotFound();
    }

    public function test_employee_cannot_be_linked_to_more_than_one_user(): void
    {
        $tenant = Tenant::factory()->create();
        $admin = $this->userWithPermissions($tenant, 'employees.link_user');
        $firstUser = User::factory()->create(['tenant_id' => $tenant->id]);
        $secondUser = User::factory()->create(['tenant_id' => $tenant->id]);
        $employee = Employee::factory()->create(['tenant_id' => $tenant->id, 'user_id' => $firstUser->id]);

        $response = $this->actingAs($admin)
            ->postJson($this->url($tenant, "employees/{$employee->id}/link-user"), ['user_id' => $secondUser->id]);

        $response->assertStatus(422)->assertJsonValidationErrors('user_id');
        $this->assertSame($firstUser->id, $employee->fresh()->user_id);
    }

    public function test_user_cannot_be_linked_to_more_than_one_employee(): void
    {
        $tenant = Tenant::factory()->create();
        $admin = $this->userWithPermissions($tenant, 'employees.link_user');
        $targetUser = User::factory()->create(['tenant_id' => $tenant->id]);
        Employee::factory()->create(['tenant_id' => $tenant->id, 'user_id' => $targetUser->id]);
        $secondEmployee = Employee::factory()->create(['tenant_id' => $tenant->id]);

        $response = $this->actingAs($admin)
            ->postJson($this->url($tenant, "employees/{$secondEmployee->id}/link-user"), ['user_id' => $targetUser->id]);

        $response->assertStatus(422)->assertJsonValidationErrors('user_id');
        $this->assertNull($secondEmployee->fresh()->user_id);
    }

    public function test_tenant_admin_can_unlink_employee_user(): void
    {
        $tenant = Tenant::factory()->create();
        $admin = $this->userWithPermissions($tenant, 'employees.unlink_user');
        $targetUser = User::factory()->create(['tenant_id' => $tenant->id]);
        $employee = Employee::factory()->create([
            'tenant_id' => $tenant->id, 'user_id' => $targetUser->id, 'linked_at' => now(),
        ]);

        $response = $this->actingAs($admin)->deleteJson($this->url($tenant, "employees/{$employee->id}/unlink-user"));

        $response->assertOk();
        $employee->refresh();
        $this->assertNull($employee->user_id);
        $this->assertNull($employee->linked_at);
    }

    public function test_user_without_permission_cannot_unlink(): void
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        $targetUser = User::factory()->create(['tenant_id' => $tenant->id]);
        $employee = Employee::factory()->create(['tenant_id' => $tenant->id, 'user_id' => $targetUser->id]);

        $response = $this->actingAs($user)->deleteJson($this->url($tenant, "employees/{$employee->id}/unlink-user"));

        $response->assertForbidden();
        $this->assertSame($targetUser->id, $employee->fresh()->user_id);
    }

    public function test_link_creates_audit_log(): void
    {
        $tenant = Tenant::factory()->create();
        $admin = $this->userWithPermissions($tenant, 'employees.link_user');
        $targetUser = User::factory()->create(['tenant_id' => $tenant->id]);
        $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);

        $this->actingAs($admin)
            ->postJson($this->url($tenant, "employees/{$employee->id}/link-user"), ['user_id' => $targetUser->id])
            ->assertOk();

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'employee.user_linked',
            'module' => 'employees',
            'actor_user_id' => $admin->id,
            'auditable_id' => $employee->id,
            'target_user_id' => $targetUser->id,
        ]);
    }

    public function test_unlink_creates_audit_log(): void
    {
        $tenant = Tenant::factory()->create();
        $admin = $this->userWithPermissions($tenant, 'employees.unlink_user');
        $targetUser = User::factory()->create(['tenant_id' => $tenant->id]);
        $employee = Employee::factory()->create(['tenant_id' => $tenant->id, 'user_id' => $targetUser->id]);

        $this->actingAs($admin)->deleteJson($this->url($tenant, "employees/{$employee->id}/unlink-user"))->assertOk();

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'employee.user_unlinked',
            'module' => 'employees',
            'actor_user_id' => $admin->id,
            'auditable_id' => $employee->id,
            'target_user_id' => $targetUser->id,
        ]);
    }

    public function test_me_employee_returns_linked_employee(): void
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        $employee = Employee::factory()->create(['tenant_id' => $tenant->id, 'user_id' => $user->id]);

        $response = $this->actingAs($user)->getJson($this->url($tenant, 'me/employee'));

        $response->assertOk();
        $this->assertSame($employee->id, $response->json('data.id'));
    }

    public function test_me_employee_returns_safe_response_when_no_employee_linked(): void
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->create(['tenant_id' => $tenant->id]);

        $response = $this->actingAs($user)->getJson($this->url($tenant, 'me/employee'));

        $response->assertNotFound();
    }

    public function test_me_employee_respects_tenant_isolation(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();
        $userA = User::factory()->create(['tenant_id' => $tenantA->id]);
        Employee::factory()->create(['tenant_id' => $tenantA->id, 'user_id' => $userA->id]);

        // userA authenticated but hitting tenant B's subdomain — blocked
        // by tenant.matches before even reaching MeController.
        $response = $this->actingAs($userA)->getJson($this->url($tenantB, 'me/employee'));

        $response->assertForbidden();
    }

    public function test_linked_employee_can_acknowledge_own_assigned_policy(): void
    {
        $tenant = Tenant::factory()->create();
        $admin = $this->userWithPermissions($tenant, 'policies.assign');
        $employeeUser = $this->userWithPermissions($tenant, 'policies.acknowledge');
        $employee = Employee::factory()->create(['tenant_id' => $tenant->id, 'user_id' => $employeeUser->id]);

        $policy = Policy::factory()->create(['tenant_id' => $tenant->id]);
        $version = PolicyVersion::factory()->create([
            'tenant_id' => $tenant->id, 'policy_id' => $policy->id, 'status' => PolicyStatus::Published,
            'published_by' => $admin->id, 'published_at' => now(),
        ]);
        $policy->update(['status' => PolicyStatus::Published, 'current_version_id' => $version->id]);

        $this->actingAs($admin)
            ->postJson($this->url($tenant, "policies/{$policy->id}/assign"), ['employee_ids' => [$employee->id]])
            ->assertCreated();

        // No employee_id in the body — resolved from the caller's own link.
        $response = $this->actingAs($employeeUser)
            ->postJson($this->url($tenant, "policies/{$policy->id}/acknowledge"), []);

        $response->assertOk();
        $this->assertDatabaseHas('policy_acknowledgements', [
            'policy_id' => $policy->id,
            'employee_id' => $employee->id,
            'acknowledgement_status' => 'acknowledged',
            'acknowledgement_method' => 'web',
        ]);
    }

    public function test_linked_employee_cannot_acknowledge_another_employees_assignment(): void
    {
        $tenant = Tenant::factory()->create();
        $admin = $this->userWithPermissions($tenant, 'policies.assign');
        $employeeUserOne = $this->userWithPermissions($tenant, 'policies.acknowledge');
        $employeeOne = Employee::factory()->create(['tenant_id' => $tenant->id, 'user_id' => $employeeUserOne->id]);
        $employeeTwo = Employee::factory()->create(['tenant_id' => $tenant->id]);

        $policy = Policy::factory()->create(['tenant_id' => $tenant->id]);
        $version = PolicyVersion::factory()->create([
            'tenant_id' => $tenant->id, 'policy_id' => $policy->id, 'status' => PolicyStatus::Published,
            'published_by' => $admin->id, 'published_at' => now(),
        ]);
        $policy->update(['status' => PolicyStatus::Published, 'current_version_id' => $version->id]);

        $this->actingAs($admin)
            ->postJson($this->url($tenant, "policies/{$policy->id}/assign"), ['employee_ids' => [$employeeTwo->id]])
            ->assertCreated();

        // employeeUserOne (linked to employeeOne) tries to acknowledge on
        // behalf of employeeTwo — should be rejected, since
        // employeeUserOne doesn't have policies.assign.
        $response = $this->actingAs($employeeUserOne)
            ->postJson($this->url($tenant, "policies/{$policy->id}/acknowledge"), ['employee_id' => $employeeTwo->id]);

        $response->assertForbidden();
        $this->assertDatabaseHas('policy_acknowledgements', [
            'employee_id' => $employeeTwo->id,
            'acknowledgement_status' => 'pending',
        ]);
    }

    public function test_user_without_linked_employee_cannot_acknowledge_policy(): void
    {
        $tenant = Tenant::factory()->create();
        $admin = $this->userWithPermissions($tenant, 'policies.assign');
        $unlinkedUser = $this->userWithPermissions($tenant, 'policies.acknowledge');
        $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);

        $policy = Policy::factory()->create(['tenant_id' => $tenant->id]);
        $version = PolicyVersion::factory()->create([
            'tenant_id' => $tenant->id, 'policy_id' => $policy->id, 'status' => PolicyStatus::Published,
            'published_by' => $admin->id, 'published_at' => now(),
        ]);
        $policy->update(['status' => PolicyStatus::Published, 'current_version_id' => $version->id]);

        $this->actingAs($admin)
            ->postJson($this->url($tenant, "policies/{$policy->id}/assign"), ['employee_ids' => [$employee->id]]);

        // No employee_id in the body, and no linked employee — cannot
        // resolve who to acknowledge for.
        $response = $this->actingAs($unlinkedUser)
            ->postJson($this->url($tenant, "policies/{$policy->id}/acknowledge"), []);

        $response->assertStatus(422);
    }

    public function test_linked_employee_cannot_acknowledge_another_tenants_policy(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();
        $employeeUser = $this->userWithPermissions($tenantA, 'policies.acknowledge');
        Employee::factory()->create(['tenant_id' => $tenantA->id, 'user_id' => $employeeUser->id]);
        $policyB = Policy::factory()->create(['tenant_id' => $tenantB->id]);

        $response = $this->actingAs($employeeUser)
            ->postJson($this->url($tenantA, "policies/{$policyB->id}/acknowledge"), []);

        $response->assertNotFound();
    }

    public function test_employee_role_has_policies_acknowledge_permission_seeded(): void
    {
        $tenant = Tenant::factory()->create();
        $employeeRole = Role::factory()->create(['tenant_id' => $tenant->id, 'slug' => 'employee']);
        $permission = Permission::query()->firstOrCreate(
            ['key' => 'policies.acknowledge'],
            ['category' => 'policies', 'is_platform_permission' => false],
        );
        $employeeRole->givePermissionTo($permission);

        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        $user->assignRole($employeeRole);

        $this->assertTrue($user->hasPermission('policies.acknowledge'));
    }

    public function test_all_new_routes_include_tenant_matches_middleware(): void
    {
        $routes = collect(Route::getRoutes())->filter(fn ($route) => in_array($route->uri(), [
            'api/v1/employees/{employee}/link-user',
            'api/v1/employees/{employee}/unlink-user',
            'api/v1/me/employee',
        ]));

        $this->assertCount(3, $routes);

        foreach ($routes as $route) {
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
        $admin = $this->userWithPermissions($tenant, 'employees.link_user');
        $admin->update(['status' => User::STATUS_INACTIVE]);
        $targetUser = User::factory()->create(['tenant_id' => $tenant->id]);
        $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);

        $response = $this->actingAs($admin)
            ->postJson($this->url($tenant, "employees/{$employee->id}/link-user"), ['user_id' => $targetUser->id]);

        $response->assertForbidden();
    }

    public function test_user_under_inactive_tenant_fails_closed(): void
    {
        $tenant = Tenant::factory()->create();
        $admin = $this->userWithPermissions($tenant, 'employees.link_user');
        $tenant->update(['status' => Tenant::STATUS_SUSPENDED]);
        $targetUser = User::factory()->create(['tenant_id' => $tenant->id]);
        $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);

        $response = $this->actingAs($admin)
            ->postJson($this->url($tenant, "employees/{$employee->id}/link-user"), ['user_id' => $targetUser->id]);

        $response->assertForbidden();
    }
}
