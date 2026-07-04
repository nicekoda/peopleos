<?php

namespace App\Models;

use App\Services\Audit\AuditLogger;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use RuntimeException;

class Role extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'tenant_id',
        'is_platform_role',
        'is_system_role',
        'name',
        'slug',
        'description',
    ];

    protected static function booted(): void
    {
        static::saving(function (self $role): void {
            if ($role->is_platform_role && $role->tenant_id !== null) {
                throw new RuntimeException('Platform roles must not belong to a tenant.');
            }

            if (! $role->is_platform_role && $role->tenant_id === null) {
                throw new RuntimeException('Tenant roles must belong to a tenant.');
            }
        });
    }

    protected function casts(): array
    {
        return [
            'is_platform_role' => 'boolean',
            'is_system_role' => 'boolean',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class, 'role_permission');
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'user_role')->withPivot('tenant_id');
    }

    /**
     * Attach a permission to this role. Rejects scope mismatches (a
     * platform role can only carry platform permissions, and vice versa)
     * rather than allowing a query-time surprise later. Used by
     * `RoleSeeder`'s bulk seeding only — deliberately does not audit-log,
     * since every `migrate:fresh --seed` would otherwise write one audit
     * entry per permission per seeded role (well over a hundred entries),
     * flooding the audit log with seeding noise. Real, single,
     * admin-driven assignment through the API goes through
     * `assignPermission()`/`removePermission()` below instead, which do
     * audit log.
     */
    public function givePermissionTo(Permission $permission): void
    {
        if ($this->is_platform_role !== $permission->is_platform_permission) {
            throw new RuntimeException('Permission scope does not match role scope (platform vs tenant).');
        }

        $this->permissions()->syncWithoutDetaching([$permission->id]);
    }

    /**
     * Checkpoint 28 — the audited counterpart to `givePermissionTo()`,
     * used by `RolePermissionController` for a single, deliberate,
     * admin-driven permission assignment. Callers must independently
     * enforce the system-role/platform-role/tenant lockdown (this method
     * only re-asserts the platform-vs-tenant scope guard already in
     * `givePermissionTo()` — it does not re-check `is_system_role`,
     * since that's a UI/workflow rule about *which roles this feature
     * allows editing*, not a data-integrity rule about the permission
     * itself).
     */
    public function assignPermission(Permission $permission, ?User $performedBy = null): void
    {
        $this->givePermissionTo($permission);

        AuditLogger::logFor(
            actor: $performedBy,
            action: 'role.permission_assigned',
            module: 'rbac',
            tenantId: $this->tenant_id,
            auditableType: self::class,
            auditableId: (string) $this->id,
            description: "Permission '{$permission->key}' assigned to role '{$this->name}'.",
            newValues: ['permission_id' => $permission->id, 'permission_key' => $permission->key],
        );
    }

    public function removePermission(Permission $permission, ?User $performedBy = null): void
    {
        $this->permissions()->detach($permission->id);

        AuditLogger::logFor(
            actor: $performedBy,
            action: 'role.permission_removed',
            module: 'rbac',
            tenantId: $this->tenant_id,
            auditableType: self::class,
            auditableId: (string) $this->id,
            description: "Permission '{$permission->key}' removed from role '{$this->name}'.",
            oldValues: ['permission_id' => $permission->id, 'permission_key' => $permission->key],
        );
    }
}
