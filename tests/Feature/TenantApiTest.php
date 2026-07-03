<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Checkpoint 22 — singleton GET/PATCH /api/v1/tenant. No {tenant} route
 * parameter exists at all: both actions always operate on
 * app(Tenant::class), the tenant tenant.matches already confirmed the
 * caller belongs to — there is no way to request a different tenant's
 * record through this endpoint (Refinement 1).
 */
class TenantApiTest extends TestCase
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

    public function test_guest_cannot_access_tenant_api(): void
    {
        $tenant = Tenant::factory()->create();

        $this->getJson($this->url($tenant, 'tenant'))->assertUnauthorized();
    }

    public function test_user_without_tenant_view_cannot_show_tenant(): void
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->create(['tenant_id' => $tenant->id]);

        $this->actingAs($user)->getJson($this->url($tenant, 'tenant'))->assertForbidden();
    }

    public function test_user_with_tenant_view_can_show_tenant(): void
    {
        $tenant = Tenant::factory()->create(['name' => 'Acme Corp']);
        $user = $this->userWithPermissions($tenant, 'tenant.view');

        $response = $this->actingAs($user)->getJson($this->url($tenant, 'tenant'));

        $response->assertOk();
        $response->assertJsonPath('data.id', $tenant->id);
        $response->assertJsonPath('data.name', 'Acme Corp');
        $response->assertJsonPath('data.subdomain', $tenant->subdomain);
        $response->assertJsonPath('data.status', $tenant->status);
    }

    public function test_user_without_tenant_update_cannot_update_tenant(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'tenant.view');

        $this->actingAs($user)->patchJson($this->url($tenant, 'tenant'), ['name' => 'New Name'])->assertForbidden();

        $this->assertSame($tenant->name, $tenant->fresh()->name);
    }

    public function test_user_with_tenant_update_can_update_name(): void
    {
        $tenant = Tenant::factory()->create(['name' => 'Old Name']);
        $user = $this->userWithPermissions($tenant, 'tenant.view', 'tenant.update');

        $response = $this->actingAs($user)->patchJson($this->url($tenant, 'tenant'), ['name' => 'New Name']);

        $response->assertOk();
        $response->assertJsonPath('data.name', 'New Name');
        $this->assertSame('New Name', $tenant->fresh()->name);
    }

    /**
     * Refinement 2 — forbidden fields must never be applied, even if
     * sent in the request body alongside a valid `name` change.
     */
    public function test_forbidden_fields_cannot_be_changed(): void
    {
        $tenant = Tenant::factory()->create(['name' => 'Old Name', 'status' => Tenant::STATUS_ACTIVE]);
        $otherTenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'tenant.view', 'tenant.update');
        $originalSubdomain = $tenant->subdomain;

        $response = $this->actingAs($user)->patchJson($this->url($tenant, 'tenant'), [
            'name' => 'New Name',
            'subdomain' => 'hijacked',
            'status' => Tenant::STATUS_SUSPENDED,
            'tenant_id' => $otherTenant->id,
            'id' => $otherTenant->id,
            'created_at' => '2000-01-01T00:00:00Z',
        ]);

        $response->assertOk();
        $fresh = $tenant->fresh();
        $this->assertSame('New Name', $fresh->name);
        $this->assertSame($originalSubdomain, $fresh->subdomain);
        $this->assertSame(Tenant::STATUS_ACTIVE, $fresh->status);
        $this->assertSame($tenant->id, $fresh->id);
    }

    public function test_tenant_a_cannot_update_tenant_b_via_body_id(): void
    {
        $tenantA = Tenant::factory()->create(['name' => 'Tenant A']);
        $tenantB = Tenant::factory()->create(['name' => 'Tenant B']);
        $userA = $this->userWithPermissions($tenantA, 'tenant.view', 'tenant.update');

        $response = $this->actingAs($userA)->patchJson($this->url($tenantA, 'tenant'), [
            'name' => 'Renamed',
            'tenant_id' => $tenantB->id,
        ]);

        $response->assertOk();
        $this->assertSame('Renamed', $tenantA->fresh()->name);
        $this->assertSame('Tenant B', $tenantB->fresh()->name);
    }

    public function test_dashboard_style_tenant_matches_is_enforced(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();
        $userA = $this->userWithPermissions($tenantA, 'tenant.view');

        $this->actingAs($userA)->getJson($this->url($tenantB, 'tenant'))->assertForbidden();
    }

    public function test_platform_super_admin_is_blocked_from_tenant_api(): void
    {
        $admin = User::factory()->platformAdmin()->create();

        $showResponse = $this->actingAs($admin)->getJson('http://'.config('tenancy.base_domain').'/api/v1/tenant');
        $showResponse->assertForbidden();

        $updateResponse = $this->actingAs($admin)->patchJson('http://'.config('tenancy.base_domain').'/api/v1/tenant', ['name' => 'x']);
        $updateResponse->assertForbidden();
    }

    /**
     * Refinement 3 — audit metadata carries only safe values.
     */
    public function test_tenant_update_writes_audit_log_with_safe_metadata(): void
    {
        $tenant = Tenant::factory()->create(['name' => 'Old Name']);
        $user = $this->userWithPermissions($tenant, 'tenant.view', 'tenant.update');

        $this->actingAs($user)->patchJson($this->url($tenant, 'tenant'), ['name' => 'New Name'])->assertOk();

        $log = AuditLog::query()->where('action', 'tenant.updated')->first();

        $this->assertNotNull($log);
        $this->assertSame($tenant->id, $log->tenant_id);
        $this->assertSame('Old Name', $log->metadata['old_name']);
        $this->assertSame('New Name', $log->metadata['new_name']);
        $this->assertSame($tenant->id, $log->metadata['tenant_id']);
        $this->assertSame($user->id, $log->metadata['actor_user_id']);
    }

    public function test_no_update_no_audit_log_when_name_unchanged(): void
    {
        $tenant = Tenant::factory()->create(['name' => 'Same Name']);
        $user = $this->userWithPermissions($tenant, 'tenant.view', 'tenant.update');

        $this->actingAs($user)->patchJson($this->url($tenant, 'tenant'), ['name' => 'Same Name'])->assertOk();

        $this->assertDatabaseMissing('audit_logs', ['action' => 'tenant.updated']);
    }
}
