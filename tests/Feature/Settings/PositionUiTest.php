<?php

namespace Tests\Feature\Settings;

use App\Models\Permission;
use App\Models\Position;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Checkpoint 32 — /settings/positions(/create)(/{id}/edit). Same
 * shape as every other module UI test — permission gating, guest
 * redirects, tenant isolation, and IDs-only props for the edit page.
 */
class PositionUiTest extends TestCase
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

    public function test_guest_cannot_access_positions_ui(): void
    {
        $tenant = Tenant::factory()->create();
        $position = Position::factory()->create(['tenant_id' => $tenant->id]);

        foreach ([
            'settings/positions',
            'settings/positions/create',
            "settings/positions/{$position->id}/edit",
        ] as $path) {
            $this->get($this->url($tenant, $path))->assertRedirect(route('login'));
        }
    }

    public function test_user_without_view_cannot_access_list_page(): void
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->create(['tenant_id' => $tenant->id]);

        $this->actingAs($user)->get($this->url($tenant, 'settings/positions'))->assertForbidden();
    }

    public function test_user_with_view_can_access_list_page(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'positions.view');

        $response = $this->actingAs($user)->get($this->url($tenant, 'settings/positions'));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page->component('Settings/Positions/Index'));
    }

    public function test_user_without_create_cannot_access_create_page(): void
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->create(['tenant_id' => $tenant->id]);

        $this->actingAs($user)->get($this->url($tenant, 'settings/positions/create'))->assertForbidden();
    }

    public function test_user_with_create_can_access_create_page(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'positions.create');

        $response = $this->actingAs($user)->get($this->url($tenant, 'settings/positions/create'));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page->component('Settings/Positions/Create'));
    }

    public function test_user_without_update_cannot_access_edit_page(): void
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        $position = Position::factory()->create(['tenant_id' => $tenant->id]);

        $this->actingAs($user)->get($this->url($tenant, "settings/positions/{$position->id}/edit"))->assertForbidden();
    }

    public function test_user_with_update_can_access_edit_page(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'positions.update');
        $position = Position::factory()->create(['tenant_id' => $tenant->id]);

        $response = $this->actingAs($user)->get($this->url($tenant, "settings/positions/{$position->id}/edit"));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page->component('Settings/Positions/Edit')->where('positionId', $position->id));
    }

    public function test_user_without_delete_permission_cannot_archive_via_api(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'positions.view');
        $position = Position::factory()->create(['tenant_id' => $tenant->id]);

        $this->actingAs($user)->deleteJson(
            'http://'.$tenant->subdomain.'.'.config('tenancy.base_domain')."/api/v1/positions/{$position->id}"
        )->assertForbidden();
    }

    public function test_cross_tenant_position_id_returns_404_on_edit_page(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();
        $userA = $this->userWithPermissions($tenantA, 'positions.update');
        $positionB = Position::factory()->create(['tenant_id' => $tenantB->id]);

        $this->actingAs($userA)->get($this->url($tenantA, "settings/positions/{$positionB->id}/edit"))->assertNotFound();
    }

    public function test_edit_page_props_contain_only_position_id(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'positions.update');
        $position = Position::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Confidential Position Name']);

        $response = $this->actingAs($user)->get($this->url($tenant, "settings/positions/{$position->id}/edit"));

        $page = $response->viewData('page');
        $this->assertSame(
            ['positionId'],
            array_keys(array_diff_key($page['props'], ['errors' => 1, 'auth' => 1, 'tenant' => 1])),
        );
        $this->assertStringNotContainsString('Confidential Position Name', json_encode($page['props']));
    }

    public function test_list_page_props_contain_no_ids(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'positions.view');

        $response = $this->actingAs($user)->get($this->url($tenant, 'settings/positions'));

        $page = $response->viewData('page');
        $this->assertSame([], array_keys(array_diff_key($page['props'], ['errors' => 1, 'auth' => 1, 'tenant' => 1])));
    }
}
