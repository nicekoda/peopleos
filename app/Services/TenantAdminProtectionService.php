<?php

namespace App\Services;

use App\Models\User;

/**
 * One rule, reused everywhere it matters (Checkpoint 23, Refinement 4):
 * a tenant must never be left with zero holders of its "Tenant Admin"
 * role. This single method backs both the status-update safeguard (a
 * status change that would leave nobody else holding the role) and the
 * role-removal safeguard (removing the role itself) — deliberately one
 * shared check rather than two separate ad-hoc ones, so the rule can't
 * silently drift between the two call sites. Applies regardless of who
 * performs the action, not just self-service — another admin (or a
 * bug) deactivating the sole remaining admin is exactly as dangerous as
 * doing it to yourself.
 */
class TenantAdminProtectionService
{
    public const TENANT_ADMIN_SLUG = 'tenant-admin';

    public function isTenantAdmin(User $user): bool
    {
        return $user->roles()->where('slug', self::TENANT_ADMIN_SLUG)->exists();
    }

    /**
     * True if $user currently holds the tenant-admin role and no other
     * user in the same tenant also holds it — i.e. the action under
     * consideration (a status change away from active, or removing this
     * role) would leave the tenant with zero Tenant Admins.
     */
    public function wouldLeaveTenantWithoutAdmin(User $user): bool
    {
        if (! $this->isTenantAdmin($user)) {
            return false;
        }

        $otherAdminCount = User::query()
            ->where('tenant_id', $user->tenant_id)
            ->where('id', '!=', $user->id)
            ->whereHas('roles', fn ($query) => $query->where('slug', self::TENANT_ADMIN_SLUG))
            ->count();

        return $otherAdminCount === 0;
    }
}
