<?php

namespace Tests\Feature\Positions;

use App\Models\AuditLog;
use App\Models\Permission;
use App\Models\Position;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Checkpoint 32 — Employee Lifecycle Foundation. Position already
 * uses BelongsToTenant (Checkpoint 26), the standard two-layer tenant
 * pattern (global scope + the controller's explicit ownership check),
 * same shape as DocumentCategoryApiTest.
 */
class PositionApiTest extends TestCase
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
    public function test_guest_cannot_access_position_api(): void
    {
        $tenant = Tenant::factory()->create();

        $this->getJson($this->url($tenant, 'positions'))->assertUnauthorized();
    }

    // 2/3: view permission gating
    public function test_user_without_view_permission_cannot_list_positions(): void
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->create(['tenant_id' => $tenant->id]);

        $this->actingAs($user)->getJson($this->url($tenant, 'positions'))->assertForbidden();
    }

    public function test_user_with_view_permission_can_list_and_view_positions(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'positions.view');
        $position = Position::factory()->count(2)->create(['tenant_id' => $tenant->id])->first();

        $listResponse = $this->actingAs($user)->getJson($this->url($tenant, 'positions'));
        $listResponse->assertOk();
        $this->assertCount(2, $listResponse->json('data'));

        $showResponse = $this->actingAs($user)->getJson($this->url($tenant, "positions/{$position->id}"));
        $showResponse->assertOk();
        $showResponse->assertJsonPath('data.id', $position->id);
    }

    // 4/5: create permission gating
    public function test_user_without_create_permission_cannot_create_position(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'positions.view');

        $response = $this->actingAs($user)->postJson($this->url($tenant, 'positions'), ['name' => 'Engineering']);

        $response->assertForbidden();
    }

    public function test_user_with_create_permission_can_create_position(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'positions.create');

        $response = $this->actingAs($user)->postJson($this->url($tenant, 'positions'), ['name' => 'Engineering']);

        $response->assertCreated();
        $response->assertJsonPath('data.slug', 'engineering');
        $response->assertJsonPath('data.status', 'active');
        $this->assertDatabaseHas('positions', ['name' => 'Engineering', 'slug' => 'engineering', 'tenant_id' => $tenant->id]);
    }

    // 6/7: update permission gating
    public function test_user_without_update_permission_cannot_update_position(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'positions.view');
        $position = Position::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Original']);

        $response = $this->actingAs($user)->patchJson($this->url($tenant, "positions/{$position->id}"), ['name' => 'Hacked']);

        $response->assertForbidden();
        $this->assertSame('Original', $position->fresh()->name);
    }

    public function test_user_with_update_permission_can_update_position(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'positions.update');
        $position = Position::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Before']);

        $response = $this->actingAs($user)->patchJson($this->url($tenant, "positions/{$position->id}"), ['name' => 'After']);

        $response->assertOk();
        $response->assertJsonPath('data.name', 'After');
    }

    // slug is never accepted from the request, even on update
    public function test_slug_cannot_be_changed_via_update(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'positions.update');
        $position = Position::factory()->create(['tenant_id' => $tenant->id, 'slug' => 'original-slug']);

        $response = $this->actingAs($user)->patchJson($this->url($tenant, "positions/{$position->id}"), [
            'name' => $position->name,
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
        $user = $this->userWithPermissions($tenant, 'positions.create');

        $response = $this->actingAs($user)->postJson($this->url($tenant, 'positions'), [
            'name' => 'Engineering',
            'tenant_id' => $otherTenant->id,
            'status' => 'inactive',
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.status', 'active');
        $this->assertDatabaseHas('positions', ['name' => 'Engineering', 'tenant_id' => $tenant->id]);
        $this->assertDatabaseMissing('positions', ['name' => 'Engineering', 'tenant_id' => $otherTenant->id]);
    }

    // 8/9: delete/archive permission gating
    public function test_user_without_delete_permission_cannot_archive_position(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'positions.view');
        $position = Position::factory()->create(['tenant_id' => $tenant->id]);

        $response = $this->actingAs($user)->deleteJson($this->url($tenant, "positions/{$position->id}"));

        $response->assertForbidden();
        $this->assertNull($position->fresh()->deleted_at);
    }

    public function test_user_with_delete_permission_can_archive_position(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'positions.delete');
        $position = Position::factory()->create(['tenant_id' => $tenant->id]);

        $response = $this->actingAs($user)->deleteJson($this->url($tenant, "positions/{$position->id}"));

        $response->assertOk();
        $this->assertSoftDeleted('positions', ['id' => $position->id]);
    }

    // 10: tenant isolation
    public function test_tenant_a_cannot_view_edit_or_archive_tenant_b_position(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();
        $userA = $this->userWithPermissions($tenantA, 'positions.view', 'positions.update', 'positions.delete');
        $positionB = Position::factory()->create(['tenant_id' => $tenantB->id, 'name' => 'Untouched']);

        $this->actingAs($userA)->getJson($this->url($tenantA, "positions/{$positionB->id}"))->assertNotFound();
        $this->actingAs($userA)->patchJson($this->url($tenantA, "positions/{$positionB->id}"), ['name' => 'Hacked'])->assertNotFound();
        $this->actingAs($userA)->deleteJson($this->url($tenantA, "positions/{$positionB->id}"))->assertNotFound();

        $this->assertSame('Untouched', $positionB->fresh()->name);
        $this->assertNull($positionB->fresh()->deleted_at);
    }

    public function test_tenant_a_cannot_list_tenant_b_positions(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();
        $userA = $this->userWithPermissions($tenantA, 'positions.view');
        Position::factory()->create(['tenant_id' => $tenantA->id, 'name' => 'A Position']);
        Position::factory()->create(['tenant_id' => $tenantB->id, 'name' => 'B Position']);

        $response = $this->actingAs($userA)->getJson($this->url($tenantA, 'positions'));

        $names = collect($response->json('data'))->pluck('name');
        $this->assertTrue($names->contains('A Position'));
        $this->assertFalse($names->contains('B Position'));
    }

    // 11: Platform Super Admin blocked
    public function test_platform_super_admin_is_blocked_from_position_api(): void
    {
        $tenant = Tenant::factory()->create();
        $admin = User::factory()->platformAdmin()->create();

        $response = $this->actingAs($admin)->getJson($this->url($tenant, 'positions'));

        $response->assertForbidden();
    }

    // 12: resource safety
    public function test_position_api_does_not_expose_internal_fields(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'positions.view');
        Position::factory()->create(['tenant_id' => $tenant->id]);

        $response = $this->actingAs($user)->getJson($this->url($tenant, 'positions'));
        $body = json_encode($response->json());

        foreach (['created_by', 'updated_by', 'deleted_at'] as $internalKey) {
            $this->assertStringNotContainsString($internalKey, $body, "Response unexpectedly contains '{$internalKey}'.");
        }
        $this->assertStringNotContainsString('"tenant_id"', $body);
    }

    // 13/14/15: audit logging
    public function test_create_position_writes_audit_log(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'positions.create');

        $this->actingAs($user)->postJson($this->url($tenant, 'positions'), ['name' => 'Engineering'])->assertCreated();

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'position.created',
            'module' => 'employees',
            'actor_user_id' => $user->id,
            'tenant_id' => $tenant->id,
        ]);
    }

    public function test_update_position_writes_audit_log(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'positions.update');
        $position = Position::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Before']);

        $this->actingAs($user)->patchJson($this->url($tenant, "positions/{$position->id}"), ['name' => 'After'])->assertOk();

        $log = AuditLog::query()->where('action', 'position.updated')->where('auditable_id', $position->id)->first();
        $this->assertNotNull($log);
        $this->assertSame('Before', $log->old_values['name']);
        $this->assertSame('After', $log->new_values['name']);
    }

    public function test_archive_position_writes_audit_log(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'positions.delete');
        $position = Position::factory()->create(['tenant_id' => $tenant->id]);

        $this->actingAs($user)->deleteJson($this->url($tenant, "positions/{$position->id}"))->assertOk();

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'position.archived',
            'module' => 'employees',
            'actor_user_id' => $user->id,
            'auditable_id' => $position->id,
        ]);
    }

    public function test_all_position_routes_include_tenant_matches_middleware(): void
    {
        $positionRoutes = collect(Route::getRoutes())
            ->filter(fn ($route) => str_starts_with($route->uri(), 'api/v1/positions'));

        $this->assertGreaterThanOrEqual(5, $positionRoutes->count());

        foreach ($positionRoutes as $route) {
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
        $user = $this->userWithPermissions($tenant, 'positions.view');
        $user->update(['status' => User::STATUS_INACTIVE]);

        $response = $this->actingAs($user)->getJson($this->url($tenant, 'positions'));

        $response->assertForbidden();
    }

    public function test_user_under_inactive_tenant_fails_closed(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'positions.view');
        $tenant->update(['status' => Tenant::STATUS_SUSPENDED]);

        $response = $this->actingAs($user)->getJson($this->url($tenant, 'positions'));

        $response->assertForbidden();
    }

    public function test_name_is_unique_within_tenant(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'positions.create');
        Position::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Engineering']);

        $response = $this->actingAs($user)->postJson($this->url($tenant, 'positions'), ['name' => 'Engineering']);

        $response->assertStatus(422)->assertJsonValidationErrors('name');
    }

    public function test_same_name_can_exist_in_different_tenants(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();
        Position::factory()->create(['tenant_id' => $tenantA->id, 'name' => 'Engineering']);
        $user = $this->userWithPermissions($tenantB, 'positions.create');

        $response = $this->actingAs($user)->postJson($this->url($tenantB, 'positions'), ['name' => 'Engineering']);

        $response->assertCreated();
    }

    public function test_no_hard_delete_route_exists(): void
    {
        $positionRoutes = collect(Route::getRoutes())
            ->filter(fn ($route) => str_starts_with($route->uri(), 'api/v1/positions'))
            ->map(fn ($route) => implode('|', $route->methods()));

        // DELETE exists (soft-delete/archive only) — confirmed by the
        // controller itself never calling forceDelete(); no separate
        // hard-delete route/verb exists beyond the one DELETE route.
        $this->assertCount(5, $positionRoutes);
    }
}
