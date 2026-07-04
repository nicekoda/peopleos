<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Role\StoreRoleRequest;
use App\Http\Requests\Role\UpdateRoleRequest;
use App\Http\Resources\RoleResource;
use App\Models\Role;
use App\Models\Tenant;
use App\Services\Audit\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Str;

/**
 * Role, like User, does not use BelongsToTenant (see docs/security.md)
 * — the manual where('tenant_id', ...) + where('is_platform_role', false)
 * filter below is the only tenant/scope boundary here (Refinement 1),
 * not defense-in-depth on top of a global scope.
 *
 * Checkpoint 28 adds show()/store()/update() on top of Checkpoint 23's
 * read-only index(). Built-in (is_system_role) roles remain fully
 * view-only: create/update only ever apply to custom, tenant-admin-
 * created roles — see docs/security.md for the full "safer MVP" reasoning.
 */
class RoleController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $roles = Role::query()
            ->where('tenant_id', app(Tenant::class)->id)
            ->where('is_platform_role', false)
            ->withCount(['permissions', 'users'])
            ->orderBy('name')
            ->paginate();

        return RoleResource::collection($roles);
    }

    public function show(Request $request, Role $role): RoleResource
    {
        $this->ensureBelongsToCurrentTenant($role);

        $role->loadCount(['permissions', 'users']);
        $role->load(['permissions' => fn ($query) => $query->orderBy('category')->orderBy('key')]);

        return new RoleResource($role);
    }

    /**
     * Creates a custom (is_system_role: false) tenant role. tenant_id/
     * is_system_role/is_platform_role are set explicitly here from
     * trusted context, never from request input — StoreRoleRequest's
     * rules structurally exclude them. Slug is derived from name
     * server-side; a collision (two names slugifying the same, or a
     * name reused after its original role was soft-deleted) is
     * disambiguated with a numeric suffix rather than failing the
     * request outright.
     */
    public function store(StoreRoleRequest $request): RoleResource
    {
        $tenantId = app(Tenant::class)->id;
        $name = $request->validated('name');
        $slug = $this->uniqueSlugFor($name, $tenantId);

        $role = Role::query()->create([
            'tenant_id' => $tenantId,
            'is_platform_role' => false,
            'is_system_role' => false,
            'name' => $name,
            'slug' => $slug,
            'description' => $request->validated('description'),
        ]);

        AuditLogger::logFor(
            actor: $request->user(),
            action: 'role.created',
            module: 'rbac',
            tenantId: $tenantId,
            auditableType: Role::class,
            auditableId: (string) $role->id,
            description: "Custom role '{$role->name}' created.",
            newValues: ['name' => $role->name, 'slug' => $role->slug, 'description' => $role->description],
            ipAddress: $request->ip(),
            userAgent: $request->userAgent(),
        );

        $role->loadCount(['permissions', 'users']);

        return new RoleResource($role);
    }

    /**
     * name/description only (UpdateRoleRequest) — slug is never
     * reassigned here, regardless of what request body arrives, and a
     * system/platform/cross-tenant role is rejected before any field is
     * applied.
     */
    public function update(UpdateRoleRequest $request, Role $role): RoleResource
    {
        $this->ensureBelongsToCurrentTenant($role);
        $this->ensureNotSystemRole($role);

        $oldValues = ['name' => $role->name, 'description' => $role->description];

        $role->update([
            'name' => $request->validated('name'),
            'description' => $request->validated('description'),
        ]);

        AuditLogger::logFor(
            actor: $request->user(),
            action: 'role.updated',
            module: 'rbac',
            tenantId: $role->tenant_id,
            auditableType: Role::class,
            auditableId: (string) $role->id,
            description: "Custom role '{$role->name}' updated.",
            oldValues: $oldValues,
            newValues: ['name' => $role->name, 'description' => $role->description],
            ipAddress: $request->ip(),
            userAgent: $request->userAgent(),
        );

        $role->loadCount(['permissions', 'users']);

        return new RoleResource($role);
    }

    private function uniqueSlugFor(string $name, string $tenantId): string
    {
        $base = Str::slug($name);
        $slug = $base;
        $suffix = 1;

        while (Role::query()->where('tenant_id', $tenantId)->where('slug', $slug)->exists()) {
            $suffix++;
            $slug = "{$base}-{$suffix}";
        }

        return $slug;
    }

    /**
     * Defense in depth beyond the manual tenant filter above — same
     * pattern as UserController, applied here as the primary (not
     * secondary) safeguard since no global scope exists for Role.
     * Platform roles are 404'd, not 403'd — they don't exist from a
     * tenant caller's point of view at all, matching every other
     * platform-vs-tenant boundary in this app.
     */
    protected function ensureBelongsToCurrentTenant(Role $role): void
    {
        abort_if($role->is_platform_role, 404);
        abort_unless($role->tenant_id === app(Tenant::class)->id, 404);
    }

    /**
     * A system role is fully visible (already passed the tenant/
     * platform check above) but not mutable this checkpoint — 403, not
     * 404, since the role genuinely exists and is viewable, just
     * protected. See docs/security.md "Safer MVP" reasoning.
     */
    protected function ensureNotSystemRole(Role $role): void
    {
        abort_if($role->is_system_role, 403, 'System roles are protected and cannot be edited in this checkpoint.');
    }
}
