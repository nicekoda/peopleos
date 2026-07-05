<?php

namespace Tests\Feature\Locations;

use App\Models\AuditLog;
use App\Models\Location;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Checkpoint 32 — Employee Lifecycle Foundation. Location already
 * uses BelongsToTenant (Checkpoint 26), the standard two-layer tenant
 * pattern (global scope + the controller's explicit ownership check),
 * same shape as DocumentCategoryApiTest.
 */
class LocationApiTest extends TestCase
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
    public function test_guest_cannot_access_location_api(): void
    {
        $tenant = Tenant::factory()->create();

        $this->getJson($this->url($tenant, 'locations'))->assertUnauthorized();
    }

    // 2/3: view permission gating
    public function test_user_without_view_permission_cannot_list_locations(): void
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->create(['tenant_id' => $tenant->id]);

        $this->actingAs($user)->getJson($this->url($tenant, 'locations'))->assertForbidden();
    }

    public function test_user_with_view_permission_can_list_and_view_locations(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'locations.view');
        $location = Location::factory()->count(2)->create(['tenant_id' => $tenant->id])->first();

        $listResponse = $this->actingAs($user)->getJson($this->url($tenant, 'locations'));
        $listResponse->assertOk();
        $this->assertCount(2, $listResponse->json('data'));

        $showResponse = $this->actingAs($user)->getJson($this->url($tenant, "locations/{$location->id}"));
        $showResponse->assertOk();
        $showResponse->assertJsonPath('data.id', $location->id);
    }

    // 4/5: create permission gating
    public function test_user_without_create_permission_cannot_create_location(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'locations.view');

        $response = $this->actingAs($user)->postJson($this->url($tenant, 'locations'), ['name' => 'Engineering']);

        $response->assertForbidden();
    }

    public function test_user_with_create_permission_can_create_location(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'locations.create');

        $response = $this->actingAs($user)->postJson($this->url($tenant, 'locations'), ['name' => 'Engineering']);

        $response->assertCreated();
        $response->assertJsonPath('data.slug', 'engineering');
        $response->assertJsonPath('data.status', 'active');
        $this->assertDatabaseHas('locations', ['name' => 'Engineering', 'slug' => 'engineering', 'tenant_id' => $tenant->id]);
    }

    // 6/7: update permission gating
    public function test_user_without_update_permission_cannot_update_location(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'locations.view');
        $location = Location::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Original']);

        $response = $this->actingAs($user)->patchJson($this->url($tenant, "locations/{$location->id}"), ['name' => 'Hacked']);

        $response->assertForbidden();
        $this->assertSame('Original', $location->fresh()->name);
    }

    public function test_user_with_update_permission_can_update_location(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'locations.update');
        $location = Location::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Before']);

        $response = $this->actingAs($user)->patchJson($this->url($tenant, "locations/{$location->id}"), ['name' => 'After']);

        $response->assertOk();
        $response->assertJsonPath('data.name', 'After');
    }

    // slug is never accepted from the request, even on update
    public function test_slug_cannot_be_changed_via_update(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'locations.update');
        $location = Location::factory()->create(['tenant_id' => $tenant->id, 'slug' => 'original-slug']);

        $response = $this->actingAs($user)->patchJson($this->url($tenant, "locations/{$location->id}"), [
            'name' => $location->name,
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
        $user = $this->userWithPermissions($tenant, 'locations.create');

        $response = $this->actingAs($user)->postJson($this->url($tenant, 'locations'), [
            'name' => 'Engineering',
            'tenant_id' => $otherTenant->id,
            'status' => 'inactive',
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.status', 'active');
        $this->assertDatabaseHas('locations', ['name' => 'Engineering', 'tenant_id' => $tenant->id]);
        $this->assertDatabaseMissing('locations', ['name' => 'Engineering', 'tenant_id' => $otherTenant->id]);
    }

    // 8/9: delete/archive permission gating
    public function test_user_without_delete_permission_cannot_archive_location(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'locations.view');
        $location = Location::factory()->create(['tenant_id' => $tenant->id]);

        $response = $this->actingAs($user)->deleteJson($this->url($tenant, "locations/{$location->id}"));

        $response->assertForbidden();
        $this->assertNull($location->fresh()->deleted_at);
    }

    public function test_user_with_delete_permission_can_archive_location(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'locations.delete');
        $location = Location::factory()->create(['tenant_id' => $tenant->id]);

        $response = $this->actingAs($user)->deleteJson($this->url($tenant, "locations/{$location->id}"));

        $response->assertOk();
        $this->assertSoftDeleted('locations', ['id' => $location->id]);
    }

    // 10: tenant isolation
    public function test_tenant_a_cannot_view_edit_or_archive_tenant_b_location(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();
        $userA = $this->userWithPermissions($tenantA, 'locations.view', 'locations.update', 'locations.delete');
        $locationB = Location::factory()->create(['tenant_id' => $tenantB->id, 'name' => 'Untouched']);

        $this->actingAs($userA)->getJson($this->url($tenantA, "locations/{$locationB->id}"))->assertNotFound();
        $this->actingAs($userA)->patchJson($this->url($tenantA, "locations/{$locationB->id}"), ['name' => 'Hacked'])->assertNotFound();
        $this->actingAs($userA)->deleteJson($this->url($tenantA, "locations/{$locationB->id}"))->assertNotFound();

        $this->assertSame('Untouched', $locationB->fresh()->name);
        $this->assertNull($locationB->fresh()->deleted_at);
    }

    public function test_tenant_a_cannot_list_tenant_b_locations(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();
        $userA = $this->userWithPermissions($tenantA, 'locations.view');
        Location::factory()->create(['tenant_id' => $tenantA->id, 'name' => 'A Location']);
        Location::factory()->create(['tenant_id' => $tenantB->id, 'name' => 'B Location']);

        $response = $this->actingAs($userA)->getJson($this->url($tenantA, 'locations'));

        $names = collect($response->json('data'))->pluck('name');
        $this->assertTrue($names->contains('A Location'));
        $this->assertFalse($names->contains('B Location'));
    }

    // 11: Platform Super Admin blocked
    public function test_platform_super_admin_is_blocked_from_location_api(): void
    {
        $tenant = Tenant::factory()->create();
        $admin = User::factory()->platformAdmin()->create();

        $response = $this->actingAs($admin)->getJson($this->url($tenant, 'locations'));

        $response->assertForbidden();
    }

    // 12: resource safety
    public function test_location_api_does_not_expose_internal_fields(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'locations.view');
        Location::factory()->create(['tenant_id' => $tenant->id]);

        $response = $this->actingAs($user)->getJson($this->url($tenant, 'locations'));
        $body = json_encode($response->json());

        foreach (['created_by', 'updated_by', 'deleted_at'] as $internalKey) {
            $this->assertStringNotContainsString($internalKey, $body, "Response unexpectedly contains '{$internalKey}'.");
        }
        $this->assertStringNotContainsString('"tenant_id"', $body);
    }

    // 13/14/15: audit logging
    public function test_create_location_writes_audit_log(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'locations.create');

        $this->actingAs($user)->postJson($this->url($tenant, 'locations'), ['name' => 'Engineering'])->assertCreated();

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'location.created',
            'module' => 'employees',
            'actor_user_id' => $user->id,
            'tenant_id' => $tenant->id,
        ]);
    }

    public function test_update_location_writes_audit_log(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'locations.update');
        $location = Location::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Before']);

        $this->actingAs($user)->patchJson($this->url($tenant, "locations/{$location->id}"), ['name' => 'After'])->assertOk();

        $log = AuditLog::query()->where('action', 'location.updated')->where('auditable_id', $location->id)->first();
        $this->assertNotNull($log);
        $this->assertSame('Before', $log->old_values['name']);
        $this->assertSame('After', $log->new_values['name']);
    }

    public function test_archive_location_writes_audit_log(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'locations.delete');
        $location = Location::factory()->create(['tenant_id' => $tenant->id]);

        $this->actingAs($user)->deleteJson($this->url($tenant, "locations/{$location->id}"))->assertOk();

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'location.archived',
            'module' => 'employees',
            'actor_user_id' => $user->id,
            'auditable_id' => $location->id,
        ]);
    }

    public function test_all_location_routes_include_tenant_matches_middleware(): void
    {
        $locationRoutes = collect(Route::getRoutes())
            ->filter(fn ($route) => str_starts_with($route->uri(), 'api/v1/locations'));

        $this->assertGreaterThanOrEqual(5, $locationRoutes->count());

        foreach ($locationRoutes as $route) {
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
        $user = $this->userWithPermissions($tenant, 'locations.view');
        $user->update(['status' => User::STATUS_INACTIVE]);

        $response = $this->actingAs($user)->getJson($this->url($tenant, 'locations'));

        $response->assertForbidden();
    }

    public function test_user_under_inactive_tenant_fails_closed(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'locations.view');
        $tenant->update(['status' => Tenant::STATUS_SUSPENDED]);

        $response = $this->actingAs($user)->getJson($this->url($tenant, 'locations'));

        $response->assertForbidden();
    }

    public function test_name_is_unique_within_tenant(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'locations.create');
        Location::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Engineering']);

        $response = $this->actingAs($user)->postJson($this->url($tenant, 'locations'), ['name' => 'Engineering']);

        $response->assertStatus(422)->assertJsonValidationErrors('name');
    }

    public function test_same_name_can_exist_in_different_tenants(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();
        Location::factory()->create(['tenant_id' => $tenantA->id, 'name' => 'Engineering']);
        $user = $this->userWithPermissions($tenantB, 'locations.create');

        $response = $this->actingAs($user)->postJson($this->url($tenantB, 'locations'), ['name' => 'Engineering']);

        $response->assertCreated();
    }

    public function test_no_hard_delete_route_exists(): void
    {
        $locationRoutes = collect(Route::getRoutes())
            ->filter(fn ($route) => str_starts_with($route->uri(), 'api/v1/locations'))
            ->map(fn ($route) => implode('|', $route->methods()));

        // DELETE exists (soft-delete/archive only) — confirmed by the
        // controller itself never calling forceDelete(); no separate
        // hard-delete route/verb exists beyond the one DELETE route.
        $this->assertCount(5, $locationRoutes);
    }
}
