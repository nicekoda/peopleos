<?php

namespace App\Models;

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
     * rather than allowing a query-time surprise later.
     */
    public function givePermissionTo(Permission $permission): void
    {
        if ($this->is_platform_role !== $permission->is_platform_permission) {
            throw new RuntimeException('Permission scope does not match role scope (platform vs tenant).');
        }

        $this->permissions()->syncWithoutDetaching([$permission->id]);
    }
}
