<?php

namespace Tests\Feature\AuditLog;

use App\Models\AuditLog;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Checkpoint 24 — /settings/security/audit-logs,
 * /settings/security/audit-logs/{auditLog}. Same shape as every other
 * module UI test — permission gating, guest redirects, tenant
 * isolation, and IDs-only props for the detail page.
 */
class AuditLogUiTest extends TestCase
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

    public function test_guest_cannot_access_audit_log_ui(): void
    {
        $tenant = Tenant::factory()->create();
        $log = AuditLog::factory()->create(['tenant_id' => $tenant->id]);

        foreach (['settings/security/audit-logs', "settings/security/audit-logs/{$log->id}"] as $path) {
            $this->get($this->url($tenant, $path))->assertRedirect(route('login'));
        }
    }

    public function test_user_without_audit_view_cannot_access_audit_log_ui(): void
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->create(['tenant_id' => $tenant->id]);

        $this->actingAs($user)->get($this->url($tenant, 'settings/security/audit-logs'))->assertForbidden();
    }

    public function test_user_with_audit_view_can_access_audit_log_ui(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'audit.view');

        $response = $this->actingAs($user)->get($this->url($tenant, 'settings/security/audit-logs'));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page->component('Settings/AuditLogs'));
    }

    public function test_cross_tenant_audit_log_id_returns_404(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();
        $userA = $this->userWithPermissions($tenantA, 'audit.view');
        $logB = AuditLog::factory()->create(['tenant_id' => $tenantB->id]);

        $this->actingAs($userA)->get($this->url($tenantA, "settings/security/audit-logs/{$logB->id}"))->assertNotFound();
    }

    public function test_detail_page_props_contain_only_audit_log_id(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'audit.view');
        $log = AuditLog::factory()->create([
            'tenant_id' => $tenant->id,
            'description' => 'Confidential audit description string',
        ]);

        $response = $this->actingAs($user)->get($this->url($tenant, "settings/security/audit-logs/{$log->id}"));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page->component('Settings/AuditLogShow')->where('auditLogId', $log->id));

        $page = $response->viewData('page');
        $this->assertSame(['auditLogId'], array_keys(array_diff_key($page['props'], ['errors' => 1, 'auth' => 1, 'tenant' => 1])));
        $this->assertStringNotContainsString('Confidential audit description string', json_encode($page['props']));
    }

    public function test_employee_cannot_access_audit_log_ui_by_default(): void
    {
        $tenant = Tenant::factory()->create();
        // Mirrors the seeded Employee role's actual permission set —
        // no audit.* key — see RoleSeeder.
        $employeeLikeUser = $this->userWithPermissions($tenant, 'dashboard.view', 'leave.view');

        $this->actingAs($employeeLikeUser)->get($this->url($tenant, 'settings/security/audit-logs'))->assertForbidden();
    }

    public function test_security_hub_links_to_audit_logs_when_authorised(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'audit.view');

        $response = $this->actingAs($user)->get($this->url($tenant, 'settings/security'));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page->component('Settings/Security'));
    }
}
