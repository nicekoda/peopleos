<?php

namespace Tests\Feature\Settings;

use App\Models\LeaveType;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Checkpoint 25 — /settings/leave-types(/create)(/{id}/edit). Same shape
 * as DocumentCategoryUiTest — LeaveType already uses BelongsToTenant,
 * so this is the standard two-layer tenant-isolation pattern.
 */
class LeaveTypeUiTest extends TestCase
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

    // 10: guest cannot access any page
    public function test_guest_cannot_access_leave_types_ui(): void
    {
        $tenant = Tenant::factory()->create();
        $leaveType = LeaveType::factory()->create(['tenant_id' => $tenant->id]);

        foreach ([
            'settings/leave-types',
            'settings/leave-types/create',
            "settings/leave-types/{$leaveType->id}/edit",
        ] as $path) {
            $this->get($this->url($tenant, $path))->assertRedirect(route('login'));
        }
    }

    // 11/12: list page permission gating
    public function test_user_without_view_cannot_access_list_page(): void
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->create(['tenant_id' => $tenant->id]);

        $this->actingAs($user)->get($this->url($tenant, 'settings/leave-types'))->assertForbidden();
    }

    public function test_user_with_view_can_access_list_page(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'leave_types.view');

        $response = $this->actingAs($user)->get($this->url($tenant, 'settings/leave-types'));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page->component('Settings/LeaveTypes/Index'));
    }

    // 13: create page permission gating
    public function test_user_without_create_cannot_access_create_page(): void
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->create(['tenant_id' => $tenant->id]);

        $this->actingAs($user)->get($this->url($tenant, 'settings/leave-types/create'))->assertForbidden();
    }

    public function test_user_with_create_can_access_create_page(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'leave_types.create');

        $response = $this->actingAs($user)->get($this->url($tenant, 'settings/leave-types/create'));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page->component('Settings/LeaveTypes/Create'));
    }

    // 14: edit page permission gating
    public function test_user_without_update_cannot_access_edit_page(): void
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        $leaveType = LeaveType::factory()->create(['tenant_id' => $tenant->id]);

        $this->actingAs($user)->get($this->url($tenant, "settings/leave-types/{$leaveType->id}/edit"))->assertForbidden();
    }

    public function test_user_with_update_can_access_edit_page(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'leave_types.update');
        $leaveType = LeaveType::factory()->create(['tenant_id' => $tenant->id]);

        $response = $this->actingAs($user)->get($this->url($tenant, "settings/leave-types/{$leaveType->id}/edit"));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page->component('Settings/LeaveTypes/Edit')->where('leaveTypeId', $leaveType->id));
    }

    // 15: delete/archive gating re-asserted at the permission level (the
    // API's own tests, LeaveTypeApiTest from Checkpoint 12, already
    // cover the full behavior).
    public function test_user_without_delete_permission_cannot_archive_via_api(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'leave_types.view');
        $leaveType = LeaveType::factory()->create(['tenant_id' => $tenant->id]);

        $this->actingAs($user)->deleteJson(
            'http://'.$tenant->subdomain.'.'.config('tenancy.base_domain')."/api/v1/leave-types/{$leaveType->id}"
        )->assertForbidden();
    }

    // 16: tenant isolation
    public function test_cross_tenant_leave_type_id_returns_404_on_edit_page(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();
        $userA = $this->userWithPermissions($tenantA, 'leave_types.update');
        $leaveTypeB = LeaveType::factory()->create(['tenant_id' => $tenantB->id]);

        $this->actingAs($userA)->get($this->url($tenantA, "settings/leave-types/{$leaveTypeB->id}/edit"))->assertNotFound();
    }

    // 17: props contain only the ID
    public function test_edit_page_props_contain_only_leave_type_id(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'leave_types.update');
        $leaveType = LeaveType::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Confidential Leave Type Name']);

        $response = $this->actingAs($user)->get($this->url($tenant, "settings/leave-types/{$leaveType->id}/edit"));

        $page = $response->viewData('page');
        $this->assertSame(
            ['leaveTypeId'],
            array_keys(array_diff_key($page['props'], ['errors' => 1, 'auth' => 1, 'tenant' => 1])),
        );
        $this->assertStringNotContainsString('Confidential Leave Type Name', json_encode($page['props']));
    }

    public function test_list_page_props_contain_no_ids(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'leave_types.view');

        $response = $this->actingAs($user)->get($this->url($tenant, 'settings/leave-types'));

        $page = $response->viewData('page');
        $this->assertSame([], array_keys(array_diff_key($page['props'], ['errors' => 1, 'auth' => 1, 'tenant' => 1])));
    }

    // 18: API does not expose internal fields
    public function test_leave_type_api_does_not_expose_internal_fields(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'leave_types.view');
        LeaveType::factory()->create(['tenant_id' => $tenant->id]);

        $response = $this->actingAs($user)->getJson(
            'http://'.$tenant->subdomain.'.'.config('tenancy.base_domain').'/api/v1/leave-types'
        );
        $body = json_encode($response->json());

        foreach (['created_by', 'updated_by', 'deleted_at'] as $internalKey) {
            $this->assertStringNotContainsString($internalKey, $body, "Response unexpectedly contains '{$internalKey}'.");
        }
        $this->assertStringNotContainsString('"tenant_id"', $body);
    }

    // Refinement 4: max_days_per_year can be explicitly cleared to null
    public function test_max_days_per_year_can_be_cleared_to_null(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'leave_types.update');
        $leaveType = LeaveType::factory()->create(['tenant_id' => $tenant->id, 'max_days_per_year' => 21]);

        $response = $this->actingAs($user)->patchJson(
            'http://'.$tenant->subdomain.'.'.config('tenancy.base_domain')."/api/v1/leave-types/{$leaveType->id}",
            ['max_days_per_year' => null],
        );

        $response->assertOk();
        $response->assertJsonPath('data.max_days_per_year', null);
        $this->assertNull($leaveType->fresh()->max_days_per_year);
    }
}
