<?php

namespace Tests\Feature\Auth;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use RuntimeException;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    protected function loginUrl(?Tenant $tenant = null): string
    {
        $host = $tenant
            ? "{$tenant->subdomain}.".config('tenancy.base_domain')
            : config('tenancy.base_domain');

        return "http://{$host}/login";
    }

    public function test_active_tenant_user_can_log_in(): void
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->create([
            'tenant_id' => $tenant->id,
            'password' => Hash::make('secret-password'),
        ]);

        $response = $this->post($this->loginUrl($tenant), [
            'email' => $user->email,
            'password' => 'secret-password',
        ]);

        $response->assertOk();
        $this->assertAuthenticatedAs($user);

        $user->refresh();
        $this->assertNotNull($user->last_login_at);
        $this->assertNotNull($user->last_login_ip);
    }

    public function test_inactive_user_cannot_log_in(): void
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->inactive()->create([
            'tenant_id' => $tenant->id,
            'password' => Hash::make('secret-password'),
        ]);

        $response = $this->post($this->loginUrl($tenant), [
            'email' => $user->email,
            'password' => 'secret-password',
        ]);

        $response->assertStatus(422);
        $this->assertGuest();
    }

    public function test_suspended_user_cannot_log_in(): void
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->suspended()->create([
            'tenant_id' => $tenant->id,
            'password' => Hash::make('secret-password'),
        ]);

        $response = $this->post($this->loginUrl($tenant), [
            'email' => $user->email,
            'password' => 'secret-password',
        ]);

        $response->assertStatus(422);
        $this->assertGuest();
    }

    public function test_user_under_inactive_tenant_cannot_log_in(): void
    {
        // A suspended tenant's subdomain is already rejected by
        // ResolveTenant (403) before the request reaches the login route
        // at all — the strongest possible defense (fails before any
        // credential check). LoginRequest also re-checks tenant status
        // independently as defense in depth, for any future entry point
        // that might not go through ResolveTenant first.
        $tenant = Tenant::factory()->create(['status' => Tenant::STATUS_SUSPENDED]);
        $user = User::factory()->create([
            'tenant_id' => $tenant->id,
            'password' => Hash::make('secret-password'),
        ]);

        $response = $this->post($this->loginUrl($tenant), [
            'email' => $user->email,
            'password' => 'secret-password',
        ]);

        $response->assertForbidden();
        $this->assertGuest();
    }

    public function test_platform_super_admin_can_exist_without_tenant(): void
    {
        $admin = User::factory()->platformAdmin()->create();

        $this->assertNull($admin->tenant_id);
        $this->assertTrue($admin->is_platform_admin);
    }

    public function test_platform_super_admin_can_log_in_on_base_domain(): void
    {
        $admin = User::factory()->platformAdmin()->create([
            'password' => Hash::make('secret-password'),
        ]);

        $response = $this->post($this->loginUrl(), [
            'email' => $admin->email,
            'password' => 'secret-password',
        ]);

        $response->assertOk();
        $this->assertAuthenticatedAs($admin);
    }

    public function test_tenant_user_cannot_log_in_via_a_different_tenants_subdomain(): void
    {
        $ownTenant = Tenant::factory()->create();
        $otherTenant = Tenant::factory()->create();
        $user = User::factory()->create([
            'tenant_id' => $ownTenant->id,
            'password' => Hash::make('secret-password'),
        ]);

        $response = $this->post($this->loginUrl($otherTenant), [
            'email' => $user->email,
            'password' => 'secret-password',
        ]);

        $response->assertStatus(422);
        $this->assertGuest();
    }

    public function test_normal_tenant_user_must_have_tenant_id(): void
    {
        $this->expectException(RuntimeException::class);

        User::factory()->create(['tenant_id' => null, 'is_platform_admin' => false]);
    }

    public function test_platform_admin_must_not_have_tenant_id(): void
    {
        $tenant = Tenant::factory()->create();

        $this->expectException(RuntimeException::class);

        User::factory()->create(['tenant_id' => $tenant->id, 'is_platform_admin' => true]);
    }

    public function test_user_tenant_assignment_is_explicit_not_inferred_from_context(): void
    {
        $boundTenant = Tenant::factory()->create();
        $explicitTenant = Tenant::factory()->create();

        // Even though $boundTenant is resolved/bound in the container
        // (as ResolveTenant would do for a request), the user must end up
        // with the explicitly-provided tenant_id, never silently
        // inheriting the ambient one. User intentionally does not use
        // BelongsToTenant's auto-fill behaviour.
        app()->instance(Tenant::class, $boundTenant);

        $user = User::factory()->create(['tenant_id' => $explicitTenant->id]);

        $this->assertSame($explicitTenant->id, $user->tenant_id);
        $this->assertNotSame($boundTenant->id, $user->tenant_id);
    }

    public function test_existing_tenant_resolution_still_works(): void
    {
        $tenant = Tenant::factory()->create();

        $this->get('http://'.$tenant->subdomain.'.'.config('tenancy.base_domain').'/')->assertOk();
        $this->assertTrue(app()->bound(Tenant::class));
    }

    public function test_user_passwords_are_hashed(): void
    {
        $user = User::factory()->create(['password' => 'plain-text-password']);

        $this->assertNotSame('plain-text-password', $user->password);
        $this->assertTrue(Hash::check('plain-text-password', $user->password));
    }
}
