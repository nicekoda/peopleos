<?php

namespace Tests\Feature\Auth;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * The browser/Inertia half of the content-negotiated login/logout
 * endpoint added in Checkpoint 16 — see AuthenticationTest.php for the
 * JSON-API half of the same endpoints.
 */
class InertiaAuthTest extends TestCase
{
    use RefreshDatabase;

    protected function url(Tenant $tenant, string $path): string
    {
        return 'http://'.$tenant->subdomain.'.'.config('tenancy.base_domain').'/'.$path;
    }

    public function test_login_page_loads(): void
    {
        $tenant = Tenant::factory()->create();

        $response = $this->get($this->url($tenant, 'login'));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page->component('Auth/Login'));
    }

    public function test_authenticated_user_is_redirected_away_from_login(): void
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->create(['tenant_id' => $tenant->id]);

        $response = $this->actingAs($user)->get($this->url($tenant, 'login'));

        $response->assertRedirect(route('dashboard'));
    }

    public function test_valid_browser_login_redirects_to_dashboard(): void
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->create([
            'tenant_id' => $tenant->id,
            'password' => Hash::make('secret-password'),
        ]);

        $response = $this->post($this->url($tenant, 'login'), [
            'email' => $user->email,
            'password' => 'secret-password',
        ]);

        $response->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($user);
    }

    /**
     * Invalid credentials fail safely for a real browser post — a
     * redirect back with the same generic error message
     * LoginRequest has always thrown (never revealing whether the
     * email exists), not a stack trace or debug page.
     */
    public function test_invalid_browser_login_fails_safely(): void
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->create([
            'tenant_id' => $tenant->id,
            'password' => Hash::make('secret-password'),
        ]);

        $response = $this->post($this->url($tenant, 'login'), [
            'email' => $user->email,
            'password' => 'wrong-password',
        ]);

        $response->assertRedirect();
        $response->assertSessionHasErrors('email');
        $this->assertGuest();
        $this->assertStringNotContainsString('Stack trace', $response->getContent() ?: '');
    }

    public function test_inactive_user_cannot_log_in_via_browser_flow(): void
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->inactive()->create([
            'tenant_id' => $tenant->id,
            'password' => Hash::make('secret-password'),
        ]);

        $response = $this->post($this->url($tenant, 'login'), [
            'email' => $user->email,
            'password' => 'secret-password',
        ]);

        $response->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_logout_redirects_to_login_for_browser_flow(): void
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->create(['tenant_id' => $tenant->id]);

        $response = $this->actingAs($user)->post($this->url($tenant, 'logout'));

        $response->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_guest_cannot_access_dashboard(): void
    {
        $tenant = Tenant::factory()->create();

        $response = $this->get($this->url($tenant, 'dashboard'));

        $response->assertRedirect(route('login'));
    }

    public function test_authenticated_user_can_access_dashboard(): void
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->create(['tenant_id' => $tenant->id]);

        $response = $this->actingAs($user)->get($this->url($tenant, 'dashboard'));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page->component('Dashboard'));
    }

    public function test_inactive_user_cannot_access_dashboard(): void
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->inactive()->create(['tenant_id' => $tenant->id]);

        $response = $this->actingAs($user)->get($this->url($tenant, 'dashboard'));

        $response->assertForbidden();
    }

    public function test_user_under_inactive_tenant_cannot_access_dashboard(): void
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        $tenant->update(['status' => Tenant::STATUS_SUSPENDED]);

        // A suspended tenant's subdomain is rejected by ResolveTenant
        // (403) before the request even reaches the dashboard route —
        // the strongest possible defense, same pattern already relied on
        // for login (see AuthenticationTest::test_user_under_inactive_tenant_cannot_log_in).
        $response = $this->actingAs($user)->get($this->url($tenant, 'dashboard'));

        $response->assertForbidden();
    }
}
