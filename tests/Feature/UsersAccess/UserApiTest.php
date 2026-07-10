<?php

namespace Tests\Feature\UsersAccess;

use App\Enums\EmployeeStatus;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Checkpoint 23 — GET /api/v1/users, GET /api/v1/users/{user},
 * PATCH /api/v1/users/{user}. User does NOT use BelongsToTenant (see
 * docs/security.md) — every test here that checks tenant isolation is
 * testing the *primary* defense, not a backstop on top of a global
 * scope.
 */
class UserApiTest extends TestCase
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

    protected function tenantAdminUser(Tenant $tenant): User
    {
        $user = $this->userWithPermissions($tenant, 'users.view', 'users.deactivate');
        $adminRole = Role::query()->firstOrCreate(
            ['tenant_id' => $tenant->id, 'slug' => 'tenant-admin'],
            ['name' => 'Tenant Admin', 'is_platform_role' => false],
        );
        $user->assignRole($adminRole);

        return $user;
    }

    protected function url(Tenant $tenant, string $path): string
    {
        return 'http://'.$tenant->subdomain.'.'.config('tenancy.base_domain').'/api/v1/'.$path;
    }

    public function test_guest_cannot_access_user_list(): void
    {
        $tenant = Tenant::factory()->create();

        $this->getJson($this->url($tenant, 'users'))->assertUnauthorized();
    }

    public function test_user_without_users_view_cannot_access_user_list(): void
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->create(['tenant_id' => $tenant->id]);

        $this->actingAs($user)->getJson($this->url($tenant, 'users'))->assertForbidden();
    }

    public function test_user_with_users_view_can_access_user_list(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'users.view');
        User::factory()->count(2)->create(['tenant_id' => $tenant->id]);

        $response = $this->actingAs($user)->getJson($this->url($tenant, 'users'));

        $response->assertOk();
        $this->assertCount(3, $response->json('data'));
    }

    public function test_tenant_a_cannot_view_tenant_b_users_via_list(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();
        $userA = $this->userWithPermissions($tenantA, 'users.view');
        User::factory()->count(3)->create(['tenant_id' => $tenantB->id]);

        $response = $this->actingAs($userA)->getJson($this->url($tenantA, 'users'));

        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
    }

    public function test_tenant_a_cannot_view_tenant_b_user_by_id(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();
        $userA = $this->userWithPermissions($tenantA, 'users.view');
        $userB = User::factory()->create(['tenant_id' => $tenantB->id]);

        $this->actingAs($userA)->getJson($this->url($tenantA, "users/{$userB->id}"))->assertNotFound();
    }

    public function test_platform_admin_is_not_reachable_through_tenant_user_list_or_show(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'users.view');
        $platformAdmin = User::factory()->platformAdmin()->create();

        $listResponse = $this->actingAs($user)->getJson($this->url($tenant, 'users'));
        $listResponse->assertOk();
        $this->assertNotContains($platformAdmin->id, collect($listResponse->json('data'))->pluck('id'));

        $this->actingAs($user)->getJson($this->url($tenant, "users/{$platformAdmin->id}"))->assertNotFound();
    }

    public function test_user_api_does_not_expose_sensitive_fields(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'users.view');
        $target = User::factory()->create(['tenant_id' => $tenant->id]);

        $response = $this->actingAs($user)->getJson($this->url($tenant, "users/{$target->id}"));
        $body = json_encode($response->json());

        foreach (['password', 'remember_token', 'last_login_ip', 'email_verified_at'] as $sensitiveKey) {
            $this->assertStringNotContainsString($sensitiveKey, $body, "User API response unexpectedly contains '{$sensitiveKey}'.");
        }
    }

    public function test_tenant_admin_can_view_tenant_users(): void
    {
        $tenant = Tenant::factory()->create();
        $admin = $this->tenantAdminUser($tenant);
        $target = User::factory()->create(['tenant_id' => $tenant->id]);

        $response = $this->actingAs($admin)->getJson($this->url($tenant, "users/{$target->id}"));

        $response->assertOk();
        $response->assertJsonPath('data.id', $target->id);
    }

    // Status update
    public function test_user_status_update_requires_permission(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'users.view');
        $target = User::factory()->create(['tenant_id' => $tenant->id, 'status' => User::STATUS_ACTIVE]);

        $this->actingAs($user)->patchJson($this->url($tenant, "users/{$target->id}"), ['status' => User::STATUS_INACTIVE])
            ->assertForbidden();

        $this->assertSame(User::STATUS_ACTIVE, $target->fresh()->status);
    }

    public function test_user_with_permission_can_update_status(): void
    {
        $tenant = Tenant::factory()->create();
        $admin = $this->tenantAdminUser($tenant);
        $target = User::factory()->create(['tenant_id' => $tenant->id, 'status' => User::STATUS_ACTIVE]);

        $response = $this->actingAs($admin)->patchJson($this->url($tenant, "users/{$target->id}"), ['status' => User::STATUS_SUSPENDED]);

        $response->assertOk();
        $this->assertSame(User::STATUS_SUSPENDED, $target->fresh()->status);
    }

    public function test_user_status_update_writes_audit_log(): void
    {
        $tenant = Tenant::factory()->create();
        $admin = $this->tenantAdminUser($tenant);
        $target = User::factory()->create(['tenant_id' => $tenant->id, 'status' => User::STATUS_ACTIVE]);

        $this->actingAs($admin)->patchJson($this->url($tenant, "users/{$target->id}"), ['status' => User::STATUS_INACTIVE])->assertOk();

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'user.status_updated',
            'tenant_id' => $tenant->id,
        ]);
    }

    public function test_status_update_ignores_forbidden_fields(): void
    {
        $tenant = Tenant::factory()->create();
        $otherTenant = Tenant::factory()->create();
        $admin = $this->tenantAdminUser($tenant);
        $target = User::factory()->create(['tenant_id' => $tenant->id, 'status' => User::STATUS_ACTIVE, 'is_platform_admin' => false]);

        $response = $this->actingAs($admin)->patchJson($this->url($tenant, "users/{$target->id}"), [
            'status' => User::STATUS_INACTIVE,
            'name' => 'Hijacked Name',
            'email' => 'hijacked@example.com',
            'is_platform_admin' => true,
            'tenant_id' => $otherTenant->id,
            'password' => 'newpassword',
        ]);

        $response->assertOk();
        $fresh = $target->fresh();
        $this->assertSame(User::STATUS_INACTIVE, $fresh->status);
        $this->assertNotSame('Hijacked Name', $fresh->name);
        $this->assertNotSame('hijacked@example.com', $fresh->email);
        $this->assertFalse($fresh->is_platform_admin);
        $this->assertSame($tenant->id, $fresh->tenant_id);
    }

    // Last Tenant Admin protection
    public function test_cannot_deactivate_last_active_tenant_admin(): void
    {
        $tenant = Tenant::factory()->create();
        $admin = $this->tenantAdminUser($tenant);
        $otherActor = $this->userWithPermissions($tenant, 'users.deactivate');

        $response = $this->actingAs($otherActor)->patchJson($this->url($tenant, "users/{$admin->id}"), ['status' => User::STATUS_INACTIVE]);

        $response->assertStatus(409);
        $this->assertSame(User::STATUS_ACTIVE, $admin->fresh()->status);
    }

    public function test_cannot_suspend_self_as_last_tenant_admin(): void
    {
        $tenant = Tenant::factory()->create();
        $admin = $this->tenantAdminUser($tenant);

        $response = $this->actingAs($admin)->patchJson($this->url($tenant, "users/{$admin->id}"), ['status' => User::STATUS_SUSPENDED]);

        $response->assertStatus(409);
    }

    public function test_can_deactivate_tenant_admin_when_another_admin_exists(): void
    {
        $tenant = Tenant::factory()->create();
        $admin = $this->tenantAdminUser($tenant);
        $secondAdmin = $this->tenantAdminUser($tenant);

        $response = $this->actingAs($secondAdmin)->patchJson($this->url($tenant, "users/{$admin->id}"), ['status' => User::STATUS_INACTIVE]);

        $response->assertOk();
        $this->assertSame(User::STATUS_INACTIVE, $admin->fresh()->status);
    }

    public function test_platform_super_admin_gets_safe_behaviour_on_user_api(): void
    {
        $platformAdmin = User::factory()->platformAdmin()->create();

        $response = $this->actingAs($platformAdmin)->getJson('http://'.config('tenancy.base_domain').'/api/v1/users');

        $response->assertForbidden();
    }

    // Checkpoint 43 — POST /api/v1/users (the first user-creation endpoint).

    public function test_guest_cannot_create_user(): void
    {
        $tenant = Tenant::factory()->create();

        $this->postJson($this->url($tenant, 'users'), [
            'name' => 'New Hire', 'email' => 'new.hire@example.com',
            'password' => 'password123', 'password_confirmation' => 'password123',
        ])->assertUnauthorized();
    }

    public function test_user_without_users_create_cannot_create_user(): void
    {
        $tenant = Tenant::factory()->create();
        $actor = $this->userWithPermissions($tenant, 'users.view');
        $role = Role::factory()->create(['tenant_id' => $tenant->id]);

        $response = $this->actingAs($actor)->postJson($this->url($tenant, 'users'), [
            'name' => 'New Hire', 'email' => 'new.hire@example.com',
            'password' => 'password123', 'password_confirmation' => 'password123',
            'role_id' => $role->id,
        ]);

        $response->assertForbidden();
        $this->assertDatabaseMissing('users', ['email' => 'new.hire@example.com']);
    }

    public function test_user_with_users_create_can_create_user(): void
    {
        $tenant = Tenant::factory()->create();
        $actor = $this->userWithPermissions($tenant, 'users.create');
        $role = Role::factory()->create(['tenant_id' => $tenant->id]);

        $response = $this->actingAs($actor)->postJson($this->url($tenant, 'users'), [
            'name' => 'New Hire', 'email' => 'new.hire@example.com',
            'password' => 'password123', 'password_confirmation' => 'password123',
            'role_id' => $role->id,
        ]);

        $response->assertCreated();
        $created = User::query()->where('email', 'new.hire@example.com')->firstOrFail();
        $this->assertSame($tenant->id, $created->tenant_id);
        $this->assertSame(User::STATUS_ACTIVE, $created->status);
        $this->assertFalse($created->is_platform_admin);
        $this->assertSame($response->json('data.id'), $created->id);
    }

    public function test_created_user_password_is_hashed_and_never_exposed(): void
    {
        $tenant = Tenant::factory()->create();
        $actor = $this->userWithPermissions($tenant, 'users.create');
        $role = Role::factory()->create(['tenant_id' => $tenant->id]);

        $response = $this->actingAs($actor)->postJson($this->url($tenant, 'users'), [
            'name' => 'New Hire', 'email' => 'new.hire@example.com',
            'password' => 'correct-horse-battery-staple', 'password_confirmation' => 'correct-horse-battery-staple',
            'role_id' => $role->id,
        ]);

        $response->assertCreated();
        $body = json_encode($response->json());
        $this->assertStringNotContainsString('correct-horse-battery-staple', $body);
        $this->assertStringNotContainsString('password', $body);

        $created = User::query()->where('email', 'new.hire@example.com')->firstOrFail();
        $this->assertTrue(Hash::check('correct-horse-battery-staple', $created->password));
    }

    public function test_create_user_assigns_role(): void
    {
        $tenant = Tenant::factory()->create();
        $actor = $this->userWithPermissions($tenant, 'users.create');
        $role = Role::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Line Manager']);

        $response = $this->actingAs($actor)->postJson($this->url($tenant, 'users'), [
            'name' => 'New Hire', 'email' => 'new.hire@example.com',
            'password' => 'password123', 'password_confirmation' => 'password123',
            'role_id' => $role->id,
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.roles.0.id', $role->id);
        $created = User::query()->where('email', 'new.hire@example.com')->firstOrFail();
        $this->assertTrue($created->hasRole($role->slug));
    }

    public function test_create_user_can_link_to_employee(): void
    {
        $tenant = Tenant::factory()->create();
        $actor = $this->userWithPermissions($tenant, 'users.create');
        $role = Role::factory()->create(['tenant_id' => $tenant->id]);
        $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);

        $response = $this->actingAs($actor)->postJson($this->url($tenant, 'users'), [
            'name' => 'New Hire', 'email' => 'new.hire@example.com',
            'password' => 'password123', 'password_confirmation' => 'password123',
            'role_id' => $role->id, 'employee_id' => $employee->id,
        ]);

        $response->assertCreated();
        $created = User::query()->where('email', 'new.hire@example.com')->firstOrFail();
        $employee->refresh();
        $this->assertSame($created->id, $employee->user_id);
        $this->assertNotNull($employee->linked_at);
        $this->assertSame($actor->id, $employee->linked_by);
        $response->assertJsonPath('data.linked_employee.id', $employee->id);
    }

    public function test_create_user_cannot_link_already_linked_employee(): void
    {
        $tenant = Tenant::factory()->create();
        $actor = $this->userWithPermissions($tenant, 'users.create');
        $role = Role::factory()->create(['tenant_id' => $tenant->id]);
        $existingUser = User::factory()->create(['tenant_id' => $tenant->id]);
        $employee = Employee::factory()->create(['tenant_id' => $tenant->id, 'user_id' => $existingUser->id]);

        $response = $this->actingAs($actor)->postJson($this->url($tenant, 'users'), [
            'name' => 'New Hire', 'email' => 'new.hire@example.com',
            'password' => 'password123', 'password_confirmation' => 'password123',
            'role_id' => $role->id, 'employee_id' => $employee->id,
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('employee_id');
        $this->assertDatabaseMissing('users', ['email' => 'new.hire@example.com']);
    }

    public function test_create_user_cannot_link_terminated_employee(): void
    {
        $tenant = Tenant::factory()->create();
        $actor = $this->userWithPermissions($tenant, 'users.create');
        $role = Role::factory()->create(['tenant_id' => $tenant->id]);
        $employee = Employee::factory()->create(['tenant_id' => $tenant->id, 'status' => EmployeeStatus::Terminated->value]);

        $response = $this->actingAs($actor)->postJson($this->url($tenant, 'users'), [
            'name' => 'New Hire', 'email' => 'new.hire@example.com',
            'password' => 'password123', 'password_confirmation' => 'password123',
            'role_id' => $role->id, 'employee_id' => $employee->id,
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('employee_id');
    }

    public function test_create_user_cannot_link_another_tenants_employee(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();
        $actor = $this->userWithPermissions($tenantA, 'users.create');
        $role = Role::factory()->create(['tenant_id' => $tenantA->id]);
        $employeeB = Employee::factory()->create(['tenant_id' => $tenantB->id]);

        $response = $this->actingAs($actor)->postJson($this->url($tenantA, 'users'), [
            'name' => 'New Hire', 'email' => 'new.hire@example.com',
            'password' => 'password123', 'password_confirmation' => 'password123',
            'role_id' => $role->id, 'employee_id' => $employeeB->id,
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('employee_id');
    }

    public function test_create_user_role_must_belong_to_current_tenant(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();
        $actor = $this->userWithPermissions($tenantA, 'users.create');
        $roleB = Role::factory()->create(['tenant_id' => $tenantB->id]);

        $response = $this->actingAs($actor)->postJson($this->url($tenantA, 'users'), [
            'name' => 'New Hire', 'email' => 'new.hire@example.com',
            'password' => 'password123', 'password_confirmation' => 'password123',
            'role_id' => $roleB->id,
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('role_id');
    }

    public function test_create_user_cannot_assign_platform_role(): void
    {
        $tenant = Tenant::factory()->create();
        $actor = $this->userWithPermissions($tenant, 'users.create');
        $platformRole = Role::factory()->platform()->create();

        $response = $this->actingAs($actor)->postJson($this->url($tenant, 'users'), [
            'name' => 'New Hire', 'email' => 'new.hire@example.com',
            'password' => 'password123', 'password_confirmation' => 'password123',
            'role_id' => $platformRole->id,
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('role_id');
    }

    public function test_email_must_be_globally_unique(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();
        $actor = $this->userWithPermissions($tenantA, 'users.create');
        $role = Role::factory()->create(['tenant_id' => $tenantA->id]);
        User::factory()->create(['tenant_id' => $tenantB->id, 'email' => 'taken@example.com']);

        $response = $this->actingAs($actor)->postJson($this->url($tenantA, 'users'), [
            'name' => 'New Hire', 'email' => 'taken@example.com',
            'password' => 'password123', 'password_confirmation' => 'password123',
            'role_id' => $role->id,
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('email');
    }

    public function test_forged_platform_admin_flag_is_ignored(): void
    {
        $tenant = Tenant::factory()->create();
        $actor = $this->userWithPermissions($tenant, 'users.create');
        $role = Role::factory()->create(['tenant_id' => $tenant->id]);

        $response = $this->actingAs($actor)->postJson($this->url($tenant, 'users'), [
            'name' => 'New Hire', 'email' => 'new.hire@example.com',
            'password' => 'password123', 'password_confirmation' => 'password123',
            'role_id' => $role->id, 'is_platform_admin' => true, 'tenant_id' => Tenant::factory()->create()->id,
            'status' => 'suspended',
        ]);

        $response->assertCreated();
        $created = User::query()->where('email', 'new.hire@example.com')->firstOrFail();
        $this->assertFalse($created->is_platform_admin);
        $this->assertSame($tenant->id, $created->tenant_id);
        $this->assertSame(User::STATUS_ACTIVE, $created->status);
    }

    public function test_create_user_writes_audit_log(): void
    {
        $tenant = Tenant::factory()->create();
        $actor = $this->userWithPermissions($tenant, 'users.create');
        $role = Role::factory()->create(['tenant_id' => $tenant->id]);

        $this->actingAs($actor)->postJson($this->url($tenant, 'users'), [
            'name' => 'New Hire', 'email' => 'new.hire@example.com',
            'password' => 'password123', 'password_confirmation' => 'password123',
            'role_id' => $role->id,
        ])->assertCreated();

        $created = User::query()->where('email', 'new.hire@example.com')->firstOrFail();

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'user.created',
            'module' => 'users',
            'actor_user_id' => $actor->id,
            'target_user_id' => $created->id,
        ]);
        // The role assignment writes its own independent audit log too
        // (HasPermissions::assignRole()).
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'role.assigned',
            'module' => 'rbac',
            'target_user_id' => $created->id,
        ]);

        $auditRow = AuditLog::query()->where('action', 'user.created')->firstOrFail();
        $this->assertStringNotContainsString('password123', json_encode($auditRow->new_values));
    }

    public function test_create_user_route_includes_tenant_matches_middleware(): void
    {
        $routes = collect(Route::getRoutes())->filter(fn ($route) => $route->uri() === 'api/v1/users' && in_array('POST', $route->methods()));

        $this->assertCount(1, $routes);

        foreach ($routes as $route) {
            $this->assertContains('tenant.matches', $route->gatherMiddleware());
        }
    }
}
