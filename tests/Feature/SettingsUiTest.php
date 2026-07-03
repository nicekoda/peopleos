<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Checkpoint 22 — Settings landing page and its sub-pages. Same
 * "access, not data" two-layer design as DashboardApiTest (Checkpoint
 * 21): tenant.settings.view only grants reaching /settings; each
 * section is independently gated by its own permission. Which *named*
 * roles (Tenant Admin/HR Manager/HR Officer/Auditor/Employee/Line
 * Manager) get tenant.settings.view is a RoleSeeder mapping decision,
 * verified by a live smoke test against the real seeded demo accounts
 * (see docs/testing.md) — these tests verify the permission-key
 * behavior itself, generically, the same split used by every other
 * module's UI test file in this project.
 */
class SettingsUiTest extends TestCase
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

    public function test_guest_cannot_access_settings(): void
    {
        $tenant = Tenant::factory()->create();

        $this->get($this->url($tenant, 'settings'))->assertRedirect(route('login'));
    }

    public function test_user_without_tenant_settings_view_cannot_access_settings(): void
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->create(['tenant_id' => $tenant->id]);

        $this->actingAs($user)->get($this->url($tenant, 'settings'))->assertForbidden();
    }

    public function test_user_with_tenant_settings_view_can_access_settings(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'tenant.settings.view');

        $response = $this->actingAs($user)->get($this->url($tenant, 'settings'));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page->component('Settings/Index'));
    }

    public function test_platform_super_admin_gets_safe_settings_page(): void
    {
        $admin = User::factory()->platformAdmin()->create();

        $response = $this->actingAs($admin)->get('http://'.config('tenancy.base_domain').'/settings');

        $response->assertOk();
        $page = $response->viewData('page');
        $this->assertNull($page['props']['tenant']);
    }

    public function test_cross_tenant_session_reuse_does_not_show_wrong_tenant_settings(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();
        $userA = $this->userWithPermissions($tenantA, 'tenant.settings.view');

        $this->actingAs($userA)->get($this->url($tenantB, 'settings'))->assertForbidden();
    }

    // /settings/company requires tenant.view specifically, not just tenant.settings.view
    public function test_settings_company_requires_tenant_view(): void
    {
        $tenant = Tenant::factory()->create();
        $userWithoutPermission = $this->userWithPermissions($tenant, 'tenant.settings.view');
        $userWithPermission = $this->userWithPermissions($tenant, 'tenant.settings.view', 'tenant.view');

        $this->actingAs($userWithoutPermission)->get($this->url($tenant, 'settings/company'))->assertForbidden();

        $response = $this->actingAs($userWithPermission)->get($this->url($tenant, 'settings/company'));
        $response->assertOk();
        $response->assertInertia(fn ($page) => $page->component('Settings/Company'));
    }

    public function test_settings_company_props_contain_no_tenant_data(): void
    {
        $tenant = Tenant::factory()->create(['name' => 'Confidential Tenant Name']);
        $user = $this->userWithPermissions($tenant, 'tenant.settings.view', 'tenant.view');

        $response = $this->actingAs($user)->get($this->url($tenant, 'settings/company'));

        $page = $response->viewData('page');
        $this->assertSame([], array_keys(array_diff_key($page['props'], ['errors' => 1, 'auth' => 1, 'tenant' => 1])));
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function placeholderRouteProvider(): array
    {
        return [
            'access' => ['settings/access', 'users.view'],
            'document-categories' => ['settings/document-categories', 'document_categories.view'],
            'leave-types' => ['settings/leave-types', 'leave_types.view'],
            'security' => ['settings/security', 'audit.view'],
            'integrations' => ['settings/integrations', 'tenant.settings.view'],
        ];
    }

    /**
     * 18: every placeholder route is backend-permission-gated, both
     * directions — not just invisible in the nav.
     */
    public function test_placeholder_routes_are_permission_gated(): void
    {
        foreach (self::placeholderRouteProvider() as [$path, $permission]) {
            $tenant = Tenant::factory()->create();
            $withoutPermission = User::factory()->create(['tenant_id' => $tenant->id]);
            $withPermission = $this->userWithPermissions($tenant, $permission);

            $this->actingAs($withoutPermission)->get($this->url($tenant, $path))
                ->assertForbidden();
            $this->actingAs($withPermission)->get($this->url($tenant, $path))
                ->assertOk();
        }
    }

    // 19: no secrets/tokens/storage paths/audit internals/debug data anywhere
    public function test_settings_pages_do_not_expose_sensitive_data(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions(
            $tenant,
            'tenant.settings.view', 'tenant.view', 'users.view', 'document_categories.view',
            'leave_types.view', 'audit.view',
        );

        foreach (array_merge(['settings', 'settings/company'], array_column(self::placeholderRouteProvider(), 0)) as $path) {
            $response = $this->actingAs($user)->get($this->url($tenant, $path));
            $body = json_encode($response->viewData('page')['props']);

            foreach (['password', 'remember_token', 'storage_path', 'storage_disk', 'api_key', 'secret', 'token'] as $sensitiveKey) {
                $this->assertStringNotContainsString($sensitiveKey, $body, "Route [{$path}] props unexpectedly contain '{$sensitiveKey}'.");
            }
        }
    }
}
