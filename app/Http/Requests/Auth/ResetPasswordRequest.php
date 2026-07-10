<?php

namespace App\Http\Requests\Auth;

use App\Models\Tenant;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\Rules\Password as PasswordRule;

/**
 * Checkpoint 44 — the actual password change, given a token. token/email
 * come from the emailed link (or the form's hidden/prefilled fields);
 * password is confirmed the same way StoreUserRequest's password field
 * already is (Checkpoint 43).
 */
class ResetPasswordRequest extends FormRequest
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
            'token' => ['required', 'string'],
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'confirmed', PasswordRule::min(8)],
        ];
    }

    /**
     * Returns true only on a genuine reset. Same tenant-boundary check as
     * ForgotPasswordRequest::sendResetLinkIfEligible() — a token+email
     * pair that would otherwise be valid is still rejected if submitted
     * from the wrong tenant's subdomain (defense in depth: the normal
     * flow already only ever lands a user on their own tenant's reset
     * page, since the emailed link is tenant-aware — see
     * AppServiceProvider::boot()). Every attempt is audit-logged, success
     * or failure, mirroring LoginRequest's own always-log-the-attempt
     * pattern.
     */
    public function reset(): bool
    {
        $email = $this->input('email');
        $user = User::query()->where('email', $email)->first();
        $resolvedTenant = app()->bound(Tenant::class) ? app(Tenant::class) : null;

        $eligible = $user !== null && ($user->is_platform_admin
            ? $resolvedTenant === null
            : $resolvedTenant !== null && $resolvedTenant->id === $user->tenant_id);

        if (! $eligible) {
            AuditLogger::log(
                action: 'password_reset.failed',
                module: 'auth',
                tenantId: $user?->tenant_id ?? $resolvedTenant?->id,
                targetUserId: $user?->id,
                description: 'Password reset attempt rejected: token/email did not resolve to an eligible account on this domain.',
                metadata: $user ? [] : ['attempted_email' => $email],
                ipAddress: $this->ip(),
                userAgent: $this->userAgent(),
                severity: 'warning',
            );

            return false;
        }

        $status = Password::reset(
            $this->only('email', 'password', 'password_confirmation', 'token'),
            function (User $user, string $password): void {
                $user->forceFill(['password' => $password])->save();

                event(new PasswordReset($user));

                AuditLogger::log(
                    action: 'password_reset.completed',
                    module: 'auth',
                    tenantId: $user->tenant_id,
                    targetUserId: $user->id,
                    description: "User #{$user->id} completed a password reset.",
                    ipAddress: $this->ip(),
                    userAgent: $this->userAgent(),
                );
            },
        );

        if ($status !== Password::PASSWORD_RESET) {
            AuditLogger::log(
                action: 'password_reset.failed',
                module: 'auth',
                tenantId: $user->tenant_id,
                targetUserId: $user->id,
                description: "Password reset attempt rejected: {$status}.",
                ipAddress: $this->ip(),
                userAgent: $this->userAgent(),
                severity: 'warning',
            );

            return false;
        }

        return true;
    }
}
