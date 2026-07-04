<?php

namespace Tests\Feature\AuditLog;

use App\Models\AuditLog;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Checkpoint 24 — GET /api/v1/audit-logs, GET /api/v1/audit-logs/{auditLog}.
 * AuditLog does NOT use BelongsToTenant (see docs/security.md) — every
 * tenant-isolation test here is testing the primary defense, not a
 * backstop on top of a global scope.
 */
class AuditLogApiTest extends TestCase
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

    public function test_guest_cannot_access_audit_log_api(): void
    {
        $tenant = Tenant::factory()->create();

        $this->getJson($this->url($tenant, 'audit-logs'))->assertUnauthorized();
    }

    public function test_user_without_audit_view_cannot_access_audit_log_api(): void
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->create(['tenant_id' => $tenant->id]);

        $this->actingAs($user)->getJson($this->url($tenant, 'audit-logs'))->assertForbidden();
    }

    public function test_user_with_audit_view_can_list_audit_logs(): void
    {
        $tenant = Tenant::factory()->create();
        // userWithPermissions() itself performs a real assignRole() call,
        // which writes its own role.assigned audit log entry — the
        // baseline count below accounts for that, rather than assuming
        // an empty tenant.
        $user = $this->userWithPermissions($tenant, 'audit.view');
        $baselineCount = AuditLog::query()->where('tenant_id', $tenant->id)->count();
        AuditLog::factory()->count(3)->create(['tenant_id' => $tenant->id]);

        $response = $this->actingAs($user)->getJson($this->url($tenant, 'audit-logs'));

        $response->assertOk();
        $this->assertCount($baselineCount + 3, $response->json('data'));
    }

    public function test_user_with_audit_view_can_view_same_tenant_audit_log_detail(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'audit.view');
        $log = AuditLog::factory()->create(['tenant_id' => $tenant->id, 'action' => 'employee.created']);

        $response = $this->actingAs($user)->getJson($this->url($tenant, "audit-logs/{$log->id}"));

        $response->assertOk();
        $response->assertJsonPath('data.action', 'employee.created');
    }

    public function test_tenant_a_cannot_list_tenant_b_audit_logs(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();
        // Same accounting as above — assigning the throwaway role to
        // $userA writes its own tenantA-scoped audit log entry.
        $userA = $this->userWithPermissions($tenantA, 'audit.view');
        $baselineCount = AuditLog::query()->where('tenant_id', $tenantA->id)->count();
        AuditLog::factory()->count(3)->create(['tenant_id' => $tenantB->id]);

        $response = $this->actingAs($userA)->getJson($this->url($tenantA, 'audit-logs'));

        $response->assertOk();
        $this->assertCount($baselineCount, $response->json('data'));
    }

    public function test_tenant_a_cannot_view_tenant_b_audit_log_by_guessed_id(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();
        $userA = $this->userWithPermissions($tenantA, 'audit.view');
        $logB = AuditLog::factory()->create(['tenant_id' => $tenantB->id]);

        $this->actingAs($userA)->getJson($this->url($tenantA, "audit-logs/{$logB->id}"))->assertNotFound();
    }

    public function test_platform_level_audit_log_is_not_reachable_through_tenant_api(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'audit.view');
        $platformLog = AuditLog::factory()->create(['tenant_id' => null, 'action' => 'platform.event']);

        $listResponse = $this->actingAs($user)->getJson($this->url($tenant, 'audit-logs'));
        $listResponse->assertOk();
        $this->assertNotContains($platformLog->id, collect($listResponse->json('data'))->pluck('id'));

        $this->actingAs($user)->getJson($this->url($tenant, "audit-logs/{$platformLog->id}"))->assertNotFound();
    }

    public function test_platform_super_admin_is_blocked_from_tenant_audit_api(): void
    {
        $platformAdmin = User::factory()->platformAdmin()->create();

        $listResponse = $this->actingAs($platformAdmin)->getJson('http://'.config('tenancy.base_domain').'/api/v1/audit-logs');
        $listResponse->assertForbidden();

        $log = AuditLog::factory()->create(['tenant_id' => Tenant::factory()->create()->id]);
        $showResponse = $this->actingAs($platformAdmin)->getJson('http://'.config('tenancy.base_domain')."/api/v1/audit-logs/{$log->id}");
        $showResponse->assertForbidden();
    }

    // Sanitisation
    public function test_audit_log_resource_masks_sensitive_metadata_keys(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'audit.view');
        $log = AuditLog::factory()->create([
            'tenant_id' => $tenant->id,
            'metadata' => [
                'employee_id' => 'safe-id',
                'reason' => 'Recovering from a confidential medical procedure',
                'password' => 'should-never-appear',
                'api_key' => 'sk_live_secret',
                'storage_path' => '/private/uploads/secret.pdf',
                'ip_address' => '203.0.113.5',
            ],
        ]);

        $response = $this->actingAs($user)->getJson($this->url($tenant, "audit-logs/{$log->id}"));
        $metadata = $response->json('data.metadata');

        $this->assertSame('safe-id', $metadata['employee_id']);
        $this->assertSame('***MASKED***', $metadata['reason']);
        $this->assertSame('***MASKED***', $metadata['password']);
        $this->assertSame('***MASKED***', $metadata['api_key']);
        $this->assertSame('***MASKED***', $metadata['storage_path']);
        $this->assertSame('***MASKED***', $metadata['ip_address']);
    }

    public function test_audit_log_resource_masks_sensitive_old_values_keys(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'audit.view');
        $log = AuditLog::factory()->create([
            'tenant_id' => $tenant->id,
            'old_values' => ['name' => 'Old Name', 'bank_account_number' => '12345678'],
        ]);

        $response = $this->actingAs($user)->getJson($this->url($tenant, "audit-logs/{$log->id}"));
        $oldValues = $response->json('data.old_values');

        $this->assertSame('Old Name', $oldValues['name']);
        $this->assertSame('***MASKED***', $oldValues['bank_account_number']);
    }

    public function test_audit_log_resource_masks_sensitive_new_values_keys(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'audit.view');
        $log = AuditLog::factory()->create([
            'tenant_id' => $tenant->id,
            'new_values' => ['status' => 'active', 'session_token' => 'abc123'],
        ]);

        $response = $this->actingAs($user)->getJson($this->url($tenant, "audit-logs/{$log->id}"));
        $newValues = $response->json('data.new_values');

        $this->assertSame('active', $newValues['status']);
        $this->assertSame('***MASKED***', $newValues['session_token']);
    }

    public function test_audit_log_resource_never_exposes_ip_address_or_user_agent(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'audit.view');
        AuditLog::factory()->create([
            'tenant_id' => $tenant->id,
            'ip_address' => '203.0.113.99',
            'user_agent' => 'Mozilla/5.0 Very Specific Browser',
        ]);

        $response = $this->actingAs($user)->getJson($this->url($tenant, 'audit-logs'));
        $body = json_encode($response->json());

        $this->assertStringNotContainsString('ip_address', $body);
        $this->assertStringNotContainsString('user_agent', $body);
        $this->assertStringNotContainsString('203.0.113.99', $body);
        $this->assertStringNotContainsString('Very Specific Browser', $body);
    }

    // Filters
    public function test_filters_are_validated(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'audit.view');

        $this->actingAs($user)->getJson($this->url($tenant, 'audit-logs?severity=not-a-real-severity'))
            ->assertStatus(422);

        $this->actingAs($user)->getJson($this->url($tenant, 'audit-logs?date_from=not-a-date'))
            ->assertStatus(422);
    }

    public function test_module_filter_is_tenant_scoped(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'audit.view');
        AuditLog::factory()->create(['tenant_id' => $tenant->id, 'module' => 'leave']);
        AuditLog::factory()->create(['tenant_id' => $tenant->id, 'module' => 'policies']);
        $otherTenant = Tenant::factory()->create();
        AuditLog::factory()->create(['tenant_id' => $otherTenant->id, 'module' => 'leave']);

        $response = $this->actingAs($user)->getJson($this->url($tenant, 'audit-logs?module=leave'));

        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
    }

    public function test_severity_filter_works(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'audit.view');
        AuditLog::factory()->create(['tenant_id' => $tenant->id, 'severity' => 'critical']);
        AuditLog::factory()->create(['tenant_id' => $tenant->id, 'severity' => 'info']);

        $response = $this->actingAs($user)->getJson($this->url($tenant, 'audit-logs?severity=critical'));

        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
        $this->assertSame('critical', $response->json('data.0.severity'));
    }

    public function test_pagination_works(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'audit.view');
        AuditLog::factory()->count(20)->create(['tenant_id' => $tenant->id]);

        $response = $this->actingAs($user)->getJson($this->url($tenant, 'audit-logs'));

        $response->assertOk();
        $response->assertJsonStructure(['data', 'links', 'meta']);
        $this->assertLessThanOrEqual(15, count($response->json('data')));
    }

    // Structural: read-only, no write routes
    public function test_no_audit_log_write_routes_exist(): void
    {
        $auditRoutes = collect(Route::getRoutes())
            ->filter(fn ($route) => str_starts_with($route->uri(), 'api/v1/audit-logs'));

        $this->assertGreaterThanOrEqual(2, $auditRoutes->count());

        foreach ($auditRoutes as $route) {
            $this->assertEmpty(
                array_intersect(['POST', 'PUT', 'PATCH', 'DELETE'], $route->methods()),
                "Route [{$route->uri()}] unexpectedly allows a write method.",
            );
        }
    }
}
