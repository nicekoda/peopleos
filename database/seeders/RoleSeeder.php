<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use App\Models\Tenant;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class RoleSeeder extends Seeder
{
    /**
     * Tenant role catalog. Only tenant-admin / hr-manager / employee get a
     * real permission set attached here — the rest exist as placeholder
     * roles (no permissions yet) ready for future modules to fill in.
     * Every demo tenant gets its own copy of every role: roles are not
     * shared templates, each tenant owns its own rows (see docs/security.md).
     *
     * @var list<string>
     */
    private const TENANT_ROLE_NAMES = [
        'Tenant Admin',
        'HR Director',
        'HR Manager',
        'HR Officer',
        'Employee',
        'Line Manager',
        'Department Head',
        'Finance Manager',
        'Payroll Officer',
        'IT Support',
        'Asset Officer',
        'Recruiter',
        'Hiring Manager',
        'Executive',
        'Auditor',
        'Legal Counsel',
        'Contractor',
        'Candidate',
        'External Invitee',
        'Implementation Engineer',
    ];

    public function run(): void
    {
        $this->seedPlatformSuperAdminRole();

        Tenant::query()->whereIn('subdomain', ['uesl', 'airpeace', 'ibom'])->each(function (Tenant $tenant): void {
            $this->seedTenantRoles($tenant);
        });
    }

    private function seedPlatformSuperAdminRole(): void
    {
        $role = Role::query()->updateOrCreate(
            ['slug' => 'platform-super-admin', 'tenant_id' => null],
            ['name' => 'Platform Super Admin', 'is_platform_role' => true, 'is_system_role' => true],
        );

        Permission::query()->where('is_platform_permission', true)->get()
            ->each(fn (Permission $permission) => $role->givePermissionTo($permission));
    }

    private function seedTenantRoles(Tenant $tenant): void
    {
        $roles = collect(self::TENANT_ROLE_NAMES)->mapWithKeys(function (string $name) use ($tenant) {
            $role = Role::query()->updateOrCreate(
                ['slug' => Str::slug($name), 'tenant_id' => $tenant->id],
                ['name' => $name, 'is_platform_role' => false, 'is_system_role' => true],
            );

            return [$name => $role];
        });

        $this->grantByKeys($roles['Tenant Admin'], Permission::query()->where('is_platform_permission', false)->pluck('key')->all());

        $this->grantByKeys($roles['HR Manager'], [
            // Checkpoint 21: dashboard.view only grants access to the
            // /dashboard page/endpoint itself — every card is still
            // independently gated by its own module permission below.
            'dashboard.view',
            // Checkpoint 22: same "access, not data" pattern —
            // tenant.settings.view only grants reaching /settings;
            // HR Manager sees only the sections their existing
            // permissions already gate (document_categories.view,
            // leave_types.view below), not Company Profile (no
            // tenant.view/tenant.update grant here).
            'tenant.settings.view',
            'employees.view', 'employees.create', 'employees.update', 'employees.view_sensitive', 'employees.export',
            'documents.view', 'documents.upload', 'documents.download', 'documents.approve',
            // Checkpoint 19: read-only reference data needed to upload
            // documents correctly (sensitivity indicator, expiry-date
            // requirement) — NOT create/update/delete, which stay
            // Tenant-Admin-only. Viewing what categories exist is not
            // the same trust level as managing them. See docs/security.md.
            'document_categories.view',
            // All leave permissions, per your explicit suggested mapping
            // ("HR Manager: all leave permissions") — includes
            // leave.request/leave.cancel so an HR Manager who is also a
            // linked employee can manage their own leave, not just
            // others'.
            'leave_types.view', 'leave_types.create', 'leave_types.update', 'leave_types.delete',
            'leave.view', 'leave.view_all', 'leave.request', 'leave.approve', 'leave.reject', 'leave.cancel',
            'announcements.view', 'announcements.create', 'announcements.publish',
            'users.view',
            // Not archive/export_acknowledgements — reserved for Tenant
            // Admin, per the master spec's own suggested carve-out.
            'policies.view', 'policies.create', 'policies.update', 'policies.publish',
            'policies.assign', 'policies.acknowledge', 'policies.view_acknowledgements',
            // Link/unlink — HR Manager already trusted with
            // employees.create/update; linking user accounts to employee
            // records is a natural extension of that trust. See
            // docs/security.md for the full role-mapping rationale.
            'employees.link_user', 'employees.unlink_user',
            // Manager hierarchy (Checkpoint 13) — both, per your
            // explicit suggested mapping.
            'employees.view_team', 'employees.update_manager',
            // Leave balances (Checkpoint 15) — all, per your explicit
            // suggested mapping ("HR Manager: all leave balance
            // permissions").
            'leave_balances.view', 'leave_balances.create', 'leave_balances.update',
            'leave_balances.adjust', 'leave_balances.view_all',
        ]);

        $this->grantByKeys($roles['Employee'], [
            'dashboard.view',
            'employees.view',
            'documents.view', 'documents.upload',
            // Checkpoint 19: same read-only reference-data reasoning as
            // HR Manager above — an Employee uploading their own
            // documents needs to see category names/sensitivity/expiry
            // requirements, not manage the category catalog.
            'document_categories.view',
            // No leave.view_all — an Employee only ever sees their own
            // leave requests (LeaveRequestController::index() scopes to
            // the caller's own linked employee without it). See
            // docs/security.md.
            'leave.view', 'leave.request', 'leave.cancel',
            'announcements.view',
            // Now safe as of Checkpoint 11: acknowledge() resolves the
            // target employee from the caller's own verified link by
            // default, and rejects any attempt to acknowledge on behalf
            // of a different employee unless the caller also holds
            // policies.assign (which Employee-role users never do). See
            // docs/security.md.
            'policies.view', 'policies.acknowledge',
        ]);

        // HR Officer and Auditor get their first real permission grants
        // here (previously empty placeholders from Checkpoint 4).
        $this->grantByKeys($roles['HR Officer'], [
            'dashboard.view',
            // Checkpoint 22: reach Settings, see the Leave Types section
            // only (leave_types.view below) — not Company Profile.
            'tenant.settings.view',
            'policies.view', 'policies.create', 'policies.update',
            'policies.assign', 'policies.view_acknowledgements',
            'leave_types.view', 'leave.view', 'leave.view_all', 'leave.approve', 'leave.reject',
            // view_team only, per your accepted plan — NOT
            // update_manager. Narrower default until a real need is
            // shown, same reasoning already applied to withholding
            // employees.link_user from HR Officer in Checkpoint 11.
            'employees.view_team',
            // Leave balances (Checkpoint 15) — "view, view_all, create,
            // update, adjust if appropriate" per your suggested mapping;
            // granting all, consistent with HR Officer already holding
            // broad policy/leave permissions elsewhere.
            'leave_balances.view', 'leave_balances.create', 'leave_balances.update',
            'leave_balances.adjust', 'leave_balances.view_all',
        ]);

        $this->grantByKeys($roles['Auditor'], [
            'dashboard.view',
            // Checkpoint 22: reach Settings, see the Security & Audit
            // section (audit.view below). audit.view was previously
            // never granted to any role despite Auditor's name — no
            // audit-viewing feature exists yet for it to gate (that's
            // still write-only, see docs/security.md), so this closes a
            // naming/grant mismatch without exposing anything new.
            'tenant.settings.view', 'audit.view',
            'policies.view', 'policies.view_acknowledgements',
            'leave.view', 'leave.view_all',
            'employees.view_team',
            'leave_balances.view', 'leave_balances.view_all',
        ]);

        // Line Manager (Checkpoint 13: employees.view_team only).
        // Checkpoint 14 adds leave approval, now that
        // LeaveRequestController::approve()/reject() are scoped by
        // ManagerHierarchyService::directlyManages() — leave.approve/
        // leave.reject are no longer sufficient on their own (see
        // resolveApprovalScope()), so granting them here no longer
        // repeats the "unscoped blast radius" mistake flagged in
        // Checkpoint 12. Deliberately NOT granted: leave.view_all
        // (tenant-wide visibility — Line Manager gets leave.view_team
        // instead, direct reports only), leave.request/leave.cancel
        // (not requested this checkpoint — a Line Manager managing
        // their own leave is a separate decision). See docs/security.md.
        $this->grantByKeys($roles['Line Manager'], [
            'dashboard.view',
            'employees.view_team',
            'leave.view', 'leave.view_team', 'leave.approve', 'leave.reject',
        ]);

        // Remaining roles (HR Director, Department Head, etc.) are
        // intentionally left as placeholders with no permissions
        // attached yet.
    }

    /**
     * @param  list<string>  $keys
     */
    private function grantByKeys(Role $role, array $keys): void
    {
        Permission::query()->whereIn('key', $keys)->get()
            ->each(fn (Permission $permission) => $role->givePermissionTo($permission));
    }
}
