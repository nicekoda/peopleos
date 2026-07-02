<?php

namespace App\Http\Requests\Auth;

use App\Models\Tenant;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class LoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
        ];
    }

    /**
     * Attempt to authenticate the request's credentials.
     *
     * Order matters: credentials are verified before any status/tenant
     * check, so a failed status/tenant check never reveals information to
     * someone who hasn't already proven they hold valid credentials.
     */
    public function authenticate(): User
    {
        $this->ensureIsNotRateLimited();

        if (! Auth::attempt($this->only('email', 'password'), $this->boolean('remember'))) {
            RateLimiter::hit($this->throttleKey());

            $this->logFailedLogin(null, 'Invalid credentials.');

            throw ValidationException::withMessages([
                'email' => 'These credentials do not match our records.',
            ]);
        }

        RateLimiter::clear($this->throttleKey());

        /** @var User $user */
        $user = Auth::user();

        if (! $this->isAllowedToLoginHere($user)) {
            Auth::logout();

            $this->logFailedLogin($user, 'Wrong domain for this account.');

            throw ValidationException::withMessages([
                'email' => 'These credentials do not match our records.',
            ]);
        }

        if (! $user->isActive()) {
            Auth::logout();

            $this->logFailedLogin($user, "Account status is {$user->status}.");

            throw ValidationException::withMessages([
                'email' => "Your account is {$user->status}. Contact your administrator.",
            ]);
        }

        if (! $user->is_platform_admin && (! $user->tenant || ! $user->tenant->isActive())) {
            Auth::logout();

            $this->logFailedLogin($user, 'Tenant is not active.');

            throw ValidationException::withMessages([
                'email' => 'Your organisation is not currently active. Contact your administrator.',
            ]);
        }

        $user->forceFill([
            'last_login_at' => now(),
            'last_login_ip' => $this->ip(),
        ])->save();

        $this->session()->regenerate();

        AuditLogger::logFor(
            actor: $user,
            action: 'login.success',
            module: 'auth',
            targetUserId: $user->id,
            description: 'User logged in.',
            ipAddress: $this->ip(),
            userAgent: $this->userAgent(),
        );

        return $user;
    }

    /**
     * Logs a failed login attempt. actorUserId is only set when we've
     * already resolved which user was targeted (i.e. credentials were
     * valid but a later check failed) — for an outright bad-credentials
     * attempt, the attempted email is recorded in metadata instead, never
     * the password.
     */
    protected function logFailedLogin(?User $user, string $reason): void
    {
        $resolvedTenant = app()->bound(Tenant::class) ? app(Tenant::class) : null;

        AuditLogger::log(
            action: 'login.failed',
            module: 'auth',
            actorUserId: $user?->id,
            tenantId: $user?->tenant_id ?? $resolvedTenant?->id,
            targetUserId: $user?->id,
            description: $reason,
            metadata: $user ? null : ['attempted_email' => $this->input('email')],
            ipAddress: $this->ip(),
            userAgent: $this->userAgent(),
            severity: 'warning',
        );
    }

    /**
     * Confirm the user is authenticating on the correct domain.
     *
     * tenant_id is never trusted from the request body — the only inputs
     * are which server-resolved Tenant (if any) the request arrived under,
     * and the authenticated user's own stored tenant_id.
     */
    protected function isAllowedToLoginHere(User $user): bool
    {
        $resolvedTenant = app()->bound(Tenant::class) ? app(Tenant::class) : null;

        if ($user->is_platform_admin) {
            return $resolvedTenant === null;
        }

        return $resolvedTenant !== null && $resolvedTenant->id === $user->tenant_id;
    }

    public function ensureIsNotRateLimited(): void
    {
        if (! RateLimiter::tooManyAttempts($this->throttleKey(), 5)) {
            return;
        }

        event(new Lockout($this));

        $seconds = RateLimiter::availableIn($this->throttleKey());

        throw ValidationException::withMessages([
            'email' => "Too many login attempts. Please try again in {$seconds} seconds.",
        ]);
    }

    public function throttleKey(): string
    {
        return Str::transliterate(Str::lower($this->string('email')).'|'.$this->ip());
    }
}
