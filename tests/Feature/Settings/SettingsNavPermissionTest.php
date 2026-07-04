<?php

namespace Tests\Feature\Settings;

use App\Models\Permission;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Checkpoint 26 — pins the permission fact the Sidebar's "Settings" nav
 * link's client-side visibility check depends on
 * (resources/js/Components/Sidebar.tsx: permission: 'tenant.settings.view').
 * That check is UI-only, not the security boundary (see docs/security.md)
 * and can't be exercised by a PHP test directly — but the actual
 * permission grant it reads from shared Inertia props (auth.user.permissions,
 * set in HandleInertiaRequests from User::permissionKeys()) can be, and
 * that's what regresses silently if a future checkpoint changes which
 * permission gates /settings without updating the nav to match.
 */
class SettingsNavPermissionTest extends TestCase
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

    public function test_shared_props_include_tenant_settings_view_for_a_user_who_holds_it(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'dashboard.view', 'tenant.settings.view');

        $response = $this->actingAs($user)->get($this->url($tenant, 'dashboard'));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page->where('auth.user.permissions', fn ($permissions) => collect($permissions)->contains('tenant.settings.view')));
    }

    public function test_shared_props_omit_tenant_settings_view_for_a_user_without_it(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'dashboard.view');

        $response = $this->actingAs($user)->get($this->url($tenant, 'dashboard'));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page->where('auth.user.permissions', fn ($permissions) => ! collect($permissions)->contains('tenant.settings.view')));
    }

    public function test_settings_route_itself_is_gated_by_tenant_settings_view_not_employees_update(): void
    {
        $tenant = Tenant::factory()->create();

        // Holds employees.update (the stale permission the Sidebar used
        // to check) but not tenant.settings.view — must still be
        // rejected by the real, unchanged server-side gate.
        $user = $this->userWithPermissions($tenant, 'employees.update');

        $response = $this->actingAs($user)->get($this->url($tenant, 'settings'));

        $response->assertForbidden();
    }
}
