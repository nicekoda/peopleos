<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\User\StoreUserRequest;
use App\Http\Requests\User\UpdateUserStatusRequest;
use App\Http\Resources\UserResource;
use App\Models\Employee;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\TenantAdminProtectionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

/**
 * User and Role (Checkpoint 23) are the first tenant-scoped models in
 * this app that do NOT use BelongsToTenant — login must work before a
 * tenant is known, and Platform Super Admins need cross-tenant
 * visibility (see docs/security.md). That means the manual
 * where('tenant_id', ...) filter below is not defense-in-depth on top
 * of a global scope — it is the *only* tenant boundary here, and every
 * query in this controller applies it explicitly (Refinement 1).
 */
class UserController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $users = User::query()
            ->where('tenant_id', app(Tenant::class)->id)
            ->where('is_platform_admin', false)
            ->with(['roles', 'employee'])
            ->orderBy('name')
            ->paginate();

        return UserResource::collection($users);
    }

    public function show(Request $request, User $user): UserResource
    {
        $this->ensureBelongsToCurrentTenant($user);

        $user->loadMissing(['roles', 'employee']);

        return new UserResource($user);
    }

    /**
     * Checkpoint 43 — the first user-creation endpoint in this app.
     * users.create was reserved back in Checkpoint 23 for exactly this
     * gap (see docs/security.md's "no user invitation flow" limitation —
     * still true after this checkpoint: there's no password-reset/
     * invite-email flow, so the caller sets the initial password
     * directly, and it's never returned or logged anywhere). A separate,
     * explicit, single-permission-gated action, per your approved scope
     * choice — never triggered automatically by candidate-to-employee
     * conversion or onboarding start, same "explicit, never automatic"
     * posture EmployeeUserLinkController already documents for linking
     * an *existing* user. role assignment and the optional employee link
     * happen inside the same transaction as user creation — never a
     * moment where the account exists but is unassigned/unlinked if a
     * later step in the request were to fail.
     */
    public function store(StoreUserRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $tenantId = app(Tenant::class)->id;
        $role = Role::query()->findOrFail($validated['role_id']);
        $employee = isset($validated['employee_id']) ? Employee::query()->findOrFail($validated['employee_id']) : null;

        $user = DB::transaction(function () use ($validated, $tenantId, $role, $employee, $request) {
            $user = User::query()->create([
                'tenant_id' => $tenantId,
                'name' => $validated['name'],
                'email' => $validated['email'],
                'password' => $validated['password'],
                'status' => User::STATUS_ACTIVE,
                'is_platform_admin' => false,
                // No verification flow exists yet (same posture as
                // UserSeeder) — this is inert metadata, not a real check.
                'email_verified_at' => now(),
            ]);

            // Writes its own 'role.assigned' audit log (HasPermissions).
            $user->assignRole($role, $request->user());

            if ($employee !== null) {
                $employee->update([
                    'user_id' => $user->id,
                    'linked_at' => now(),
                    'linked_by' => $request->user()->id,
                ]);
            }

            return $user;
        });

        // Never the password — only identifying/pipeline fields, same
        // "never the sensitive input itself" rule as every other audit
        // log in this app.
        AuditLogger::logFor(
            actor: $request->user(),
            action: 'user.created',
            module: 'users',
            tenantId: $tenantId,
            auditableType: User::class,
            auditableId: (string) $user->id,
            targetUserId: $user->id,
            description: $employee !== null
                ? "User account '{$user->name}' ({$user->email}) created and linked to employee #{$employee->id}."
                : "User account '{$user->name}' ({$user->email}) created.",
            newValues: ['name' => $user->name, 'email' => $user->email, 'role_id' => $role->id, 'employee_id' => $employee?->id],
            ipAddress: $request->ip(),
            userAgent: $request->userAgent(),
        );

        $user->loadMissing(['roles', 'employee']);

        return (new UserResource($user))->response()->setStatusCode(201);
    }

    /**
     * Status-only (Refinement 3) — UpdateUserStatusRequest validates
     * exactly `status`; nothing else on the User model can be changed
     * through this action. Refinement 4: blocked if this would leave
     * the tenant with zero Tenant Admins, regardless of who is
     * performing the change.
     */
    public function update(UpdateUserStatusRequest $request, User $user): UserResource
    {
        $this->ensureBelongsToCurrentTenant($user);

        $newStatus = $request->validated('status');
        $oldStatus = $user->status;

        if ($newStatus !== User::STATUS_ACTIVE && $oldStatus === User::STATUS_ACTIVE) {
            abort_if(
                app(TenantAdminProtectionService::class)->wouldLeaveTenantWithoutAdmin($user),
                409,
                'This would leave the tenant with no active Tenant Admin.',
            );
        }

        if ($newStatus !== $oldStatus) {
            $user->update(['status' => $newStatus]);

            AuditLogger::logFor(
                actor: $request->user(),
                action: 'user.status_updated',
                module: 'users',
                tenantId: $user->tenant_id,
                auditableType: User::class,
                auditableId: (string) $user->id,
                targetUserId: $user->id,
                description: "User #{$user->id} status changed from '{$oldStatus}' to '{$newStatus}'.",
                oldValues: ['status' => $oldStatus],
                newValues: ['status' => $newStatus],
                ipAddress: $request->ip(),
                userAgent: $request->userAgent(),
            );
        }

        $user->loadMissing(['roles', 'employee']);

        return new UserResource($user);
    }

    /**
     * Defense in depth beyond the manual tenant filter above — same
     * pattern as every other controller in this app, applied here as
     * the primary (not secondary) safeguard since no global scope
     * exists for User. Also blocks Platform Super Admin records
     * explicitly (Refinement 2) — their tenant_id is always null and
     * could never match the current tenant anyway, but this makes the
     * rule explicit rather than incidental.
     */
    protected function ensureBelongsToCurrentTenant(User $user): void
    {
        abort_if($user->is_platform_admin, 404);
        abort_unless($user->tenant_id === app(Tenant::class)->id, 404);
    }
}
