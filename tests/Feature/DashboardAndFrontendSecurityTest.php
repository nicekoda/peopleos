<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Checkpoint 16 — placeholder page permission-gating and shared Inertia
 * prop safety. PermissionGate/useCan are UI-only; these tests prove the
 * *backend* route itself is gated, which is the actual security
 * boundary — see docs/security.md.
 */
class DashboardAndFrontendSecurityTest extends TestCase
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

    public function test_placeholder_page_rejects_user_without_permission(): void
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->create(['tenant_id' => $tenant->id]);

        $response = $this->actingAs($user)->get($this->url($tenant, 'employees'));

        $response->assertForbidden();
    }

    public function test_placeholder_page_allows_user_with_permission(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'employees.view');

        $response = $this->actingAs($user)->get($this->url($tenant, 'employees'));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page->component('Employees/Index'));
    }

    #[DataProvider('placeholderRouteProvider')]
    public function test_all_placeholder_pages_are_backend_permission_gated(string $path, string $permission): void
    {
        $tenant = Tenant::factory()->create();
        $withoutPermission = User::factory()->create(['tenant_id' => $tenant->id]);
        $withPermission = $this->userWithPermissions($tenant, $permission);

        $this->actingAs($withoutPermission)->get($this->url($tenant, $path))->assertForbidden();
        $this->actingAs($withPermission)->get($this->url($tenant, $path))->assertOk();
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function placeholderRouteProvider(): array
    {
        return [
            'employees' => ['employees', 'employees.view'],
            'leave' => ['leave', 'leave.view'],
            'documents' => ['documents', 'documents.view'],
            'policies' => ['policies', 'policies.view'],
            // Checkpoint 22: /settings now uses the real tenant.settings.view
            // permission (previously employees.update as a stand-in) —
            // enforced by an explicit controller check (same pattern as
            // /dashboard's dashboard.view), not blanket middleware, but
            // behaves identically for this test's purposes.
            'settings' => ['settings', 'tenant.settings.view'],
        ];
    }

    public function test_shared_inertia_props_contain_no_sensitive_fields(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'dashboard.view', 'employees.view');
        Employee::factory()->create(['tenant_id' => $tenant->id, 'user_id' => $user->id]);

        $response = $this->actingAs($user)->get($this->url($tenant, 'dashboard'));

        $response->assertOk();

        $page = $response->viewData('page');
        $propsJson = json_encode($page['props']);

        foreach (['password', 'remember_token', 'salary', 'bank', 'storage_path', 'storage_disk', 'national_id', 'ssn'] as $sensitiveKey) {
            $this->assertStringNotContainsString($sensitiveKey, $propsJson, "Shared props unexpectedly contain '{$sensitiveKey}'.");
        }
    }

    public function test_shared_props_expose_permission_list_to_frontend(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'dashboard.view', 'employees.view', 'leave.view');

        $response = $this->actingAs($user)->get($this->url($tenant, 'dashboard'));

        $page = $response->viewData('page');
        $permissions = $page['props']['auth']['user']['permissions'];

        $this->assertContains('employees.view', $permissions);
        $this->assertContains('leave.view', $permissions);
    }

    public function test_platform_super_admin_does_not_receive_tenant_context(): void
    {
        $admin = User::factory()->platformAdmin()->create();

        $response = $this->actingAs($admin)->get('http://'.config('tenancy.base_domain').'/dashboard');

        $response->assertOk();
        $page = $response->viewData('page');
        $this->assertNull($page['props']['tenant']);
    }

    public function test_tenant_user_shared_props_reflect_only_their_own_tenant(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenantA, 'dashboard.view');

        $response = $this->actingAs($user)->get($this->url($tenantA, 'dashboard'));

        $page = $response->viewData('page');
        $this->assertSame($tenantA->id, $page['props']['tenant']['id']);
        $this->assertNotSame($tenantB->id, $page['props']['tenant']['id']);
    }
}
