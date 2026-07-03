<?php

namespace Tests\Feature\Security;

use App\Models\Employee;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Regression coverage for the cross-tenant session vulnerability found
 * during Checkpoint 7: without EnsureTenantMatchesAuthenticatedUser, an
 * authenticated tenant-A user visiting tenant-B's subdomain (trivially
 * reachable in a real browser, since SESSION_DOMAIN=.peopleos.test shares
 * the session cookie across every subdomain) would pass 'auth' and
 * permission checks and reach tenant-B-scoped data.
 *
 * Deliberately independent of the Employee module (uses an ad-hoc test
 * route) — the fix is a general middleware, not an Employee-specific
 * patch, and this proves that.
 */
class TenantMatchingMiddlewareTest extends TestCase
{
    use RefreshDatabase;

    protected function registerTestRoute(): void
    {
        Route::middleware(['web', 'auth', 'tenant.matches'])
            ->get('/__test/tenant-scoped', fn () => response()->json(['ok' => true]));
    }

    public function test_authenticated_user_from_tenant_a_is_blocked_on_tenant_b_subdomain(): void
    {
        $this->registerTestRoute();

        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();
        $userA = User::factory()->create(['tenant_id' => $tenantA->id]);

        $response = $this->actingAs($userA)
            ->get('http://'.$tenantB->subdomain.'.'.config('tenancy.base_domain').'/__test/tenant-scoped');

        $response->assertForbidden();
    }

    public function test_authenticated_user_on_own_tenant_subdomain_is_allowed(): void
    {
        $this->registerTestRoute();

        $tenant = Tenant::factory()->create();
        $user = User::factory()->create(['tenant_id' => $tenant->id]);

        $response = $this->actingAs($user)
            ->get('http://'.$tenant->subdomain.'.'.config('tenancy.base_domain').'/__test/tenant-scoped');

        $response->assertOk();
    }

    public function test_platform_admin_is_blocked_on_a_tenant_subdomain(): void
    {
        $this->registerTestRoute();

        $tenant = Tenant::factory()->create();
        $admin = User::factory()->platformAdmin()->create();

        $response = $this->actingAs($admin)
            ->get('http://'.$tenant->subdomain.'.'.config('tenancy.base_domain').'/__test/tenant-scoped');

        $response->assertForbidden();
    }

    public function test_platform_admin_is_allowed_on_base_domain(): void
    {
        $this->registerTestRoute();

        $admin = User::factory()->platformAdmin()->create();

        $response = $this->actingAs($admin)
            ->get('http://'.config('tenancy.base_domain').'/__test/tenant-scoped');

        $response->assertOk();
    }

    public function test_unauthenticated_json_request_is_rejected_by_auth_not_tenant_matches(): void
    {
        $this->registerTestRoute();

        $tenant = Tenant::factory()->create();

        $response = $this->getJson('http://'.$tenant->subdomain.'.'.config('tenancy.base_domain').'/__test/tenant-scoped');

        // Rejected — by 'auth' (no user to check), not by 'tenant.matches'
        // (which passes through when there's no authenticated user). A
        // clean 401 for a JSON-expecting caller (every real API client).
        $response->assertUnauthorized();
    }

    /**
     * Checkpoint 16: a real 'login' route now exists, so a plain
     * browser (non-JSON) request to an auth-protected route redirects
     * there instead of a 401 — see the redirectGuestsTo update in
     * bootstrap/app.php. Before Checkpoint 16, this scenario was
     * untestable (no login route existed at all, so `redirectGuestsTo`
     * was hardcoded to return null and the app deliberately produced a
     * plain 401 for everyone — see git history for the Checkpoint 7 fix
     * this superseded).
     */
    public function test_unauthenticated_browser_request_redirects_to_login_not_tenant_matches(): void
    {
        $this->registerTestRoute();

        $tenant = Tenant::factory()->create();

        $response = $this->get('http://'.$tenant->subdomain.'.'.config('tenancy.base_domain').'/__test/tenant-scoped');

        $response->assertRedirect(route('login'));
    }

    public function test_tenant_mismatch_writes_a_critical_audit_log(): void
    {
        $this->registerTestRoute();

        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();
        $userA = User::factory()->create(['tenant_id' => $tenantA->id]);

        $this->actingAs($userA)
            ->get('http://'.$tenantB->subdomain.'.'.config('tenancy.base_domain').'/__test/tenant-scoped');

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'tenant.mismatch_blocked',
            'module' => 'security',
            'actor_user_id' => $userA->id,
            'tenant_id' => $tenantB->id,
            'severity' => 'critical',
        ]);
    }

    /**
     * End-to-end regression using the actual Employee endpoints (not just
     * the ad-hoc route), confirming the fix closes the exact vulnerability
     * found: tenant B's data no longer leaks to an authenticated tenant A
     * user hitting tenant B's subdomain.
     */
    public function test_employee_endpoint_no_longer_leaks_across_tenants_via_session(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();

        $userA = User::factory()->create(['tenant_id' => $tenantA->id]);
        $role = Role::factory()->create(['tenant_id' => $tenantA->id]);
        $permission = Permission::query()->firstOrCreate(
            ['key' => 'employees.view'],
            ['category' => 'employees', 'is_platform_permission' => false],
        );
        $role->givePermissionTo($permission);
        $userA->assignRole($role);

        Employee::factory()->create(['tenant_id' => $tenantB->id, 'employee_number' => 'SECRET-B-1']);

        $response = $this->actingAs($userA)
            ->getJson('http://'.$tenantB->subdomain.'.'.config('tenancy.base_domain').'/api/v1/employees');

        $response->assertForbidden();
    }
}
