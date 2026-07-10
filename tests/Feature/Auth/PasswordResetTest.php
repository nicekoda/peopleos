<?php

namespace Tests\Feature\Auth;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

/**
 * Checkpoint 44 — password reset. The security property under test
 * throughout: the response to /forgot-password never differs based on
 * whether the email exists, belongs to another tenant, or belongs to a
 * platform admin — only Notification::fake()'s assertions (an internal
 * test double, not anything a real caller can observe) reveal whether a
 * link was actually sent.
 */
class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    protected function url(?Tenant $tenant, string $path): string
    {
        $host = $tenant ? "{$tenant->subdomain}.".config('tenancy.base_domain') : config('tenancy.base_domain');

        return "http://{$host}/{$path}";
    }

    public function test_forgot_password_page_loads(): void
    {
        $tenant = Tenant::factory()->create();

        $response = $this->get($this->url($tenant, 'forgot-password'));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page->component('Auth/ForgotPassword'));
    }

    public function test_reset_password_page_loads(): void
    {
        $tenant = Tenant::factory()->create();

        $response = $this->get($this->url($tenant, 'reset-password/some-token?email=jane@example.com'));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Auth/ResetPassword')
            ->where('token', 'some-token')
            ->where('email', 'jane@example.com'));
    }

    public function test_requesting_reset_for_existing_tenant_user_sends_notification(): void
    {
        Notification::fake();
        $tenant = Tenant::factory()->create();
        $user = User::factory()->create(['tenant_id' => $tenant->id]);

        $response = $this->post($this->url($tenant, 'forgot-password'), ['email' => $user->email]);

        $response->assertRedirect();
        $response->assertSessionHas('status');
        Notification::assertSentTo($user, ResetPassword::class);
    }

    /**
     * The tenant-aware URL is what makes clicking the emailed link land
     * the user back on their own subdomain — see
     * AppServiceProvider::boot()'s ResetPassword::createUrlUsing().
     */
    public function test_reset_link_url_points_to_the_users_own_tenant_subdomain(): void
    {
        Notification::fake();
        $tenant = Tenant::factory()->create();
        $user = User::factory()->create(['tenant_id' => $tenant->id]);

        $this->post($this->url($tenant, 'forgot-password'), ['email' => $user->email]);

        Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $notification) use ($user, $tenant) {
            $mail = $notification->toMail($user);
            $expectedHost = "{$tenant->subdomain}.".config('tenancy.base_domain');

            return str_contains($mail->actionUrl, $expectedHost)
                && str_contains($mail->actionUrl, '/reset-password/')
                && str_contains($mail->actionUrl, urlencode($user->email));
        });
    }

    public function test_reset_link_url_points_to_base_domain_for_platform_admin(): void
    {
        Notification::fake();
        $admin = User::factory()->platformAdmin()->create();

        $this->post($this->url(null, 'forgot-password'), ['email' => $admin->email]);

        Notification::assertSentTo($admin, ResetPassword::class, function (ResetPassword $notification) use ($admin) {
            $mail = $notification->toMail($admin);
            $baseDomain = config('tenancy.base_domain');

            return str_contains($mail->actionUrl, "://{$baseDomain}/reset-password/");
        });
    }

    public function test_requesting_reset_for_nonexistent_email_returns_same_generic_response(): void
    {
        Notification::fake();
        $tenant = Tenant::factory()->create();

        $response = $this->post($this->url($tenant, 'forgot-password'), ['email' => 'nobody@example.com']);

        $response->assertRedirect();
        $response->assertSessionHas('status');
        Notification::assertNothingSent();
    }

    /**
     * The core tenant-isolation guarantee: a real, existing user's email
     * is never sent a reset link when the request arrives on a
     * *different* tenant's subdomain — same boundary LoginRequest
     * already enforces for logging in.
     */
    public function test_requesting_reset_for_another_tenants_user_sends_no_notification(): void
    {
        Notification::fake();
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();
        $userB = User::factory()->create(['tenant_id' => $tenantB->id]);

        $response = $this->post($this->url($tenantA, 'forgot-password'), ['email' => $userB->email]);

        $response->assertRedirect();
        $response->assertSessionHas('status');
        Notification::assertNothingSent();
    }

    public function test_requesting_reset_for_platform_admin_from_a_tenant_subdomain_sends_no_notification(): void
    {
        Notification::fake();
        $tenant = Tenant::factory()->create();
        $admin = User::factory()->platformAdmin()->create();

        $response = $this->post($this->url($tenant, 'forgot-password'), ['email' => $admin->email]);

        $response->assertRedirect();
        Notification::assertNothingSent();
    }

    public function test_forgot_password_request_writes_audit_log_for_known_email(): void
    {
        Notification::fake();
        $tenant = Tenant::factory()->create();
        $user = User::factory()->create(['tenant_id' => $tenant->id]);

        $this->post($this->url($tenant, 'forgot-password'), ['email' => $user->email]);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'password_reset.requested',
            'module' => 'auth',
            'target_user_id' => $user->id,
            'tenant_id' => $tenant->id,
        ]);
    }

    public function test_forgot_password_request_writes_audit_log_for_unknown_email(): void
    {
        $tenant = Tenant::factory()->create();

        $this->post($this->url($tenant, 'forgot-password'), ['email' => 'nobody@example.com']);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'password_reset.requested',
            'module' => 'auth',
            'target_user_id' => null,
        ]);
    }

    public function test_valid_reset_actually_changes_the_password(): void
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->create(['tenant_id' => $tenant->id, 'password' => Hash::make('old-password')]);
        $token = Password::createToken($user);

        $response = $this->post($this->url($tenant, 'reset-password'), [
            'token' => $token,
            'email' => $user->email,
            'password' => 'brand-new-password',
            'password_confirmation' => 'brand-new-password',
        ]);

        $response->assertRedirect(route('login'));
        $response->assertSessionHas('status');

        $user->refresh();
        $this->assertTrue(Hash::check('brand-new-password', $user->password));
        $this->assertFalse(Hash::check('old-password', $user->password));
    }

    public function test_reset_with_invalid_token_fails_generically(): void
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->create(['tenant_id' => $tenant->id, 'password' => Hash::make('old-password')]);

        $response = $this->post($this->url($tenant, 'reset-password'), [
            'token' => 'not-a-real-token',
            'email' => $user->email,
            'password' => 'brand-new-password',
            'password_confirmation' => 'brand-new-password',
        ]);

        $response->assertSessionHasErrors('email');
        $this->assertTrue(Hash::check('old-password', $user->fresh()->password));
    }

    /**
     * Defense in depth: even a genuinely valid token+email pair is
     * rejected if the reset is submitted from a *different* tenant's
     * subdomain than the target user belongs to.
     */
    public function test_reset_fails_when_submitted_from_the_wrong_tenants_subdomain(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();
        $userA = User::factory()->create(['tenant_id' => $tenantA->id, 'password' => Hash::make('old-password')]);
        $token = Password::createToken($userA);

        $response = $this->post($this->url($tenantB, 'reset-password'), [
            'token' => $token,
            'email' => $userA->email,
            'password' => 'brand-new-password',
            'password_confirmation' => 'brand-new-password',
        ]);

        $response->assertSessionHasErrors('email');
        $this->assertTrue(Hash::check('old-password', $userA->fresh()->password));
    }

    public function test_reset_requires_password_confirmation_to_match(): void
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        $token = Password::createToken($user);

        $response = $this->post($this->url($tenant, 'reset-password'), [
            'token' => $token,
            'email' => $user->email,
            'password' => 'brand-new-password',
            'password_confirmation' => 'a-different-password',
        ]);

        $response->assertSessionHasErrors('password');
    }

    public function test_successful_reset_writes_audit_log(): void
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        $token = Password::createToken($user);

        $this->post($this->url($tenant, 'reset-password'), [
            'token' => $token,
            'email' => $user->email,
            'password' => 'brand-new-password',
            'password_confirmation' => 'brand-new-password',
        ]);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'password_reset.completed',
            'module' => 'auth',
            'target_user_id' => $user->id,
            'tenant_id' => $tenant->id,
        ]);
    }

    public function test_failed_reset_writes_audit_log(): void
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->create(['tenant_id' => $tenant->id]);

        $this->post($this->url($tenant, 'reset-password'), [
            'token' => 'not-a-real-token',
            'email' => $user->email,
            'password' => 'brand-new-password',
            'password_confirmation' => 'brand-new-password',
        ]);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'password_reset.failed',
            'module' => 'auth',
            'target_user_id' => $user->id,
        ]);
    }

    public function test_new_password_is_hashed(): void
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        $token = Password::createToken($user);

        $this->post($this->url($tenant, 'reset-password'), [
            'token' => $token,
            'email' => $user->email,
            'password' => 'brand-new-password',
            'password_confirmation' => 'brand-new-password',
        ]);

        $this->assertNotSame('brand-new-password', $user->fresh()->password);
    }
}
