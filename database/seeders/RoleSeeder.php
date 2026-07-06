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
            // Checkpoint 32 — Employee Lifecycle Foundation. Full manage
            // rights (including archive), per your explicit approved
            // mapping: org-structure administration is core HR Manager
            // duty, unlike document_categories above which deliberately
            // stayed Tenant-Admin-only.
            'departments.view', 'departments.create', 'departments.update', 'departments.delete',
            'positions.view', 'positions.create', 'positions.update', 'positions.delete',
            'locations.view', 'locations.create', 'locations.update', 'locations.delete',
            // Checkpoint 33 — Onboarding & Offboarding Foundation. Full
            // manage rights, per your explicit approved mapping.
            'lifecycle.view', 'lifecycle.create', 'lifecycle.update', 'lifecycle.delete',
            'lifecycle.assign_task', 'lifecycle.complete_task',
            // Checkpoint 34 — HR Documents & Letter Generation Foundation.
            // Full manage rights on both templates and generated
            // documents, per your explicit approved mapping. .publish
            // added Checkpoint 36 (template version publishing) — same
            // "full manage" tier as create/update/delete.
            'hr_document_templates.view', 'hr_document_templates.create',
            'hr_document_templates.update', 'hr_document_templates.delete', 'hr_document_templates.publish',
            // Checkpoint 37 — submit/approve/reject added, per your
            // explicit approved mapping ("HR Manager / HR Director:
            // submit, approve, reject").
            'hr_generated_documents.view', 'hr_generated_documents.create',
            'hr_generated_documents.update', 'hr_generated_documents.delete', 'hr_generated_documents.generate',
            'hr_generated_documents.submit', 'hr_generated_documents.approve', 'hr_generated_documents.reject',
        ]);

        // Checkpoint 34 — HR Director previously had no permissions
        // anywhere (a placeholder role, see the class-level note above).
        // Per your explicit approval, it gets the identical HR document
        // grant as HR Manager for this checkpoint only — every other
        // module stays untouched/empty for this role.
        $this->grantByKeys($roles['HR Director'], [
            'hr_document_templates.view', 'hr_document_templates.create',
            'hr_document_templates.update', 'hr_document_templates.delete', 'hr_document_templates.publish',
            'hr_generated_documents.view', 'hr_generated_documents.create',
            'hr_generated_documents.update', 'hr_generated_documents.delete', 'hr_generated_documents.generate',
            'hr_generated_documents.submit', 'hr_generated_documents.approve', 'hr_generated_documents.reject',
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
            // Checkpoint 33 — view + complete only, scoped in the
            // controller to tasks assigned to the caller (LifecycleTaskController
            // never grants process-level access via this permission alone,
            // same "permission gates the route, controller scopes the
            // rows" shape as leave.view_team/leave.view_all).
            'lifecycle.view', 'lifecycle.complete_task',
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
            // Checkpoint 33 — a real gap found while building the
            // lifecycle Create form: GET /api/v1/employees (the picker's
            // data source) requires employees.view, which HR Officer
            // never held. Without it, HR Officer holds lifecycle.create
            // but has no way to browse the employee list to start a
            // process for someone. Same fix pattern as Checkpoint 19's
            // document_categories.view grant — view-only, no create/
            // update/delete added. Flagged and approved before adding.
            'employees.view',
            // Checkpoint 33 — a second, identically-shaped gap: the
            // lifecycle task assignee picker needs GET /api/v1/users
            // (users.view), which HR Officer also never held, despite
            // holding lifecycle.assign_task. View-only, no create/
            // update/deactivate/assign_role added. Flagged and approved
            // separately, since users.view is a broader/more sensitive
            // resource than employees.view.
            'users.view',
            // Leave balances (Checkpoint 15) — "view, view_all, create,
            // update, adjust if appropriate" per your suggested mapping;
            // granting all, consistent with HR Officer already holding
            // broad policy/leave permissions elsewhere.
            'leave_balances.view', 'leave_balances.create', 'leave_balances.update',
            'leave_balances.adjust', 'leave_balances.view_all',
            // Checkpoint 32 — view/create/update only, no delete/archive,
            // per your explicit approved mapping.
            'departments.view', 'departments.create', 'departments.update',
            'positions.view', 'positions.create', 'positions.update',
            'locations.view', 'locations.create', 'locations.update',
            // Checkpoint 33 — view/create/update/assign/complete, no
            // delete/cancel, per your explicit "safer" option.
            'lifecycle.view', 'lifecycle.create', 'lifecycle.update',
            'lifecycle.assign_task', 'lifecycle.complete_task',
            // Checkpoint 34 — view templates only (no manage rights over
            // the template catalog); view/create/generate/update on
            // generated documents, no delete/archive — per your explicit
            // approved mapping.
            'hr_document_templates.view',
            'hr_generated_documents.view', 'hr_generated_documents.create',
            'hr_generated_documents.update', 'hr_generated_documents.generate',
            // Checkpoint 37 — submit only, per your explicit approved
            // mapping ("HR Officer: create/update/generate/submit, but
            // not approve/reject") — HR Officer can never self-approve.
            'hr_generated_documents.submit',
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
            // Checkpoint 32 — view only, per your explicit approved mapping.
            'departments.view', 'positions.view', 'locations.view',
            // Checkpoint 33 — view only, per your explicit approved mapping.
            'lifecycle.view',
            // Checkpoint 34 — view only, per your explicit approved mapping.
            'hr_document_templates.view', 'hr_generated_documents.view',
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
            // Checkpoint 32 — view only, per your explicit approved mapping.
            'departments.view', 'positions.view', 'locations.view',
            // Checkpoint 33 — view + complete only, scoped in the
            // controller to tasks assigned to the caller or belonging to
            // a process for one of their direct reports (via
            // ManagerHierarchyService::directlyManages(), the same
            // scoping already used for leave.approve/leave.reject).
            'lifecycle.view', 'lifecycle.complete_task',
        ]);

        // Remaining roles (Department Head, etc.) are intentionally left
        // as placeholders with no permissions attached yet. HR Director
        // is no longer fully empty — see its grant above (Checkpoint 34).
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
