<?php

namespace App\Models\Concerns;

use App\Models\Permission;
use App\Models\Role;
use App\Models\UserPermission;
use App\Services\Audit\AuditLogger;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use RuntimeException;

/**
 * Applies RBAC to a user: role assignment, direct permission grants, and
 * the hasPermission() check every controller/middleware/gate should use.
 *
 * Assignment guards below are plain method logic, not Eloquent model
 * events — deliberately, since DatabaseSeeder's WithoutModelEvents would
 * otherwise silently bypass them during seeding. Audit logging calls are
 * likewise plain method logic for the same reason.
 */
trait HasPermissions
{
    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'user_role')->withPivot('tenant_id');
    }

    public function permissionGrants(): HasMany
    {
        return $this->hasMany(UserPermission::class);
    }

    /**
     * Assign a role to this user. Rejects platform-vs-tenant scope
     * mismatches and cross-tenant role assignment.
     */
    public function assignRole(Role $role, ?self $performedBy = null): void
    {
        if ($this->is_platform_admin !== $role->is_platform_role) {
            throw new RuntimeException('Cannot assign a role whose scope (platform vs tenant) does not match the user.');
        }

        if (! $role->is_platform_role && $role->tenant_id !== $this->tenant_id) {
            throw new RuntimeException('Cannot assign a role belonging to a different tenant.');
        }

        $this->roles()->syncWithoutDetaching([$role->id => ['tenant_id' => $role->tenant_id]]);

        AuditLogger::logFor(
            actor: $performedBy,
            action: 'role.assigned',
            module: 'rbac',
            tenantId: $this->tenant_id,
            auditableType: Role::class,
            auditableId: (string) $role->id,
            targetUserId: $this->id,
            description: "Role '{$role->name}' assigned to user #{$this->id}.",
            newValues: ['role_id' => $role->id, 'role_slug' => $role->slug],
        );
    }

    public function removeRole(Role $role, ?self $performedBy = null): void
    {
        $this->roles()->detach($role->id);

        AuditLogger::logFor(
            actor: $performedBy,
            action: 'role.removed',
            module: 'rbac',
            tenantId: $this->tenant_id,
            auditableType: Role::class,
            auditableId: (string) $role->id,
            targetUserId: $this->id,
            description: "Role '{$role->name}' removed from user #{$this->id}.",
            oldValues: ['role_id' => $role->id, 'role_slug' => $role->slug],
        );
    }

    public function hasRole(string $slug): bool
    {
        return $this->roles()->where('slug', $slug)->exists();
    }

    /**
     * Directly grant a permission to this user, outside role assignment.
     * Rejects platform-vs-tenant scope mismatches. Idempotent: granting an
     * already-granted permission just returns the existing grant.
     */
    public function grantPermission(Permission $permission, ?self $grantedBy = null, ?string $reason = null): UserPermission
    {
        if ($this->is_platform_admin !== $permission->is_platform_permission) {
            throw new RuntimeException('Cannot grant a permission whose scope (platform vs tenant) does not match the user.');
        }

        $wasRecentlyCreated = ! $this->permissionGrants()->where('permission_id', $permission->id)->exists();

        $grant = $this->permissionGrants()->firstOrCreate(
            ['permission_id' => $permission->id],
            [
                'tenant_id' => $this->tenant_id,
                'granted_by' => $grantedBy?->id,
                'reason' => $reason,
            ],
        );

        if ($wasRecentlyCreated) {
            AuditLogger::logFor(
                actor: $grantedBy,
                action: 'permission.granted',
                module: 'rbac',
                tenantId: $this->tenant_id,
                auditableType: Permission::class,
                auditableId: (string) $permission->id,
                targetUserId: $this->id,
                description: "Permission '{$permission->key}' granted directly to user #{$this->id}.",
                newValues: ['permission_id' => $permission->id, 'permission_key' => $permission->key, 'reason' => $reason],
            );
        }

        return $grant;
    }

    public function revokePermission(Permission $permission, ?self $performedBy = null): void
    {
        $this->permissionGrants()->where('permission_id', $permission->id)->delete();

        AuditLogger::logFor(
            actor: $performedBy,
            action: 'permission.revoked',
            module: 'rbac',
            tenantId: $this->tenant_id,
            auditableType: Permission::class,
            auditableId: (string) $permission->id,
            targetUserId: $this->id,
            description: "Direct permission '{$permission->key}' revoked from user #{$this->id}.",
            oldValues: ['permission_id' => $permission->id, 'permission_key' => $permission->key],
        );
    }

    /**
     * The core permission check. Fails closed: inactive user, inactive
     * tenant, or an unknown permission key all return false, never throw.
     */
    public function hasPermission(string $key): bool
    {
        if (! $this->isActive()) {
            return false;
        }

        if (! $this->is_platform_admin && (! $this->tenant || ! $this->tenant->isActive())) {
            return false;
        }

        $permission = Permission::query()->where('key', $key)->first();

        if (! $permission) {
            return false;
        }

        $hasViaRole = $this->roles()
            ->whereHas('permissions', fn ($query) => $query->where('permissions.id', $permission->id))
            ->exists();

        if ($hasViaRole) {
            return true;
        }

        return $this->permissionGrants()->where('permission_id', $permission->id)->exists();
    }
}
