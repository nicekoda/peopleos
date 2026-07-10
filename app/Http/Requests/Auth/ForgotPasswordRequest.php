<?php

namespace App\Http\Requests\Auth;

use App\Models\Tenant;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Password;

/**
 * Checkpoint 44 — request-a-reset-link. Deliberately never reveals
 * whether the submitted email exists or which tenant it belongs to:
 * PasswordResetLinkController::store() returns the exact same generic
 * message regardless of what happens here, the same "never reveal
 * account existence" posture LoginRequest already established for bad
 * credentials.
 */
class ForgotPasswordRequest extends FormRequest
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
        ];
    }

    /**
     * Sends a real reset link only when the email resolves to a user who
     * is actually allowed to authenticate on this domain — the identical
     * platform-admin-vs-tenant-vs-resolved-tenant check
     * LoginRequest::isAllowedToLoginHere() and
     * EnsureTenantMatchesAuthenticatedUser already perform, duplicated
     * here rather than shared (same reasoning StoreUserRequest already
     * documented for its own duplicated employee-state check: these
     * validate against different sources, not worth a premature shared
     * helper). A user who exists but belongs to a different tenant (or a
     * platform admin request arriving on a tenant subdomain) never gets a
     * link sent — but the caller sees no difference in the response
     * either way, so this never leaks anything.
     */
    public function sendResetLinkIfEligible(): void
    {
        $email = $this->input('email');
        $user = User::query()->where('email', $email)->first();
        $resolvedTenant = app()->bound(Tenant::class) ? app(Tenant::class) : null;

        $eligible = $user === null || ($user->is_platform_admin
            ? $resolvedTenant === null
            : $resolvedTenant !== null && $resolvedTenant->id === $user->tenant_id);

        $status = $eligible ? Password::sendResetLink(['email' => $email]) : 'ineligible.tenant_mismatch';

        AuditLogger::log(
            action: 'password_reset.requested',
            module: 'auth',
            actorUserId: null,
            tenantId: $user?->tenant_id ?? $resolvedTenant?->id,
            targetUserId: $user?->id,
            description: 'Password reset link requested.',
            metadata: $user ? ['status' => $status] : ['attempted_email' => $email, 'status' => $status],
            ipAddress: $this->ip(),
            userAgent: $this->userAgent(),
        );
    }
}
