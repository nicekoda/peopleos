<?php

namespace Database\Seeders;

use App\Models\Permission;
use Illuminate\Database\Seeder;

class PermissionSeeder extends Seeder
{
    /**
     * The full permission catalog. Permissions are global definitions —
     * not tenant-scoped themselves. What's tenant-scoped is the
     * *assignment* of a permission to a role or user (see RoleSeeder /
     * UserSeeder). is_platform_permission distinguishes permissions a
     * Platform Super Admin uses to manage tenants from permissions a
     * tenant user uses to operate inside their own tenant.
     */
    public function run(): void
    {
        $tenantPermissions = [
            'dashboard' => ['view'],
            'tenant' => ['view', 'update', 'settings.view', 'settings.update'],
            'users' => ['view', 'create', 'update', 'deactivate', 'assign_role'],
            'roles' => ['view', 'create', 'update', 'delete'],
            'permissions' => ['view', 'assign', 'grant_direct', 'revoke_direct'],
            'employees' => ['view', 'create', 'update', 'delete', 'view_sensitive', 'export', 'link_user', 'unlink_user', 'view_team', 'update_manager'],
            // Checkpoint 32 — Employee Lifecycle Foundation lookup entities.
            'departments' => ['view', 'create', 'update', 'delete'],
            'positions' => ['view', 'create', 'update', 'delete'],
            'locations' => ['view', 'create', 'update', 'delete'],
            'documents' => ['view', 'upload', 'download', 'delete', 'approve', 'view_sensitive'],
            'document_categories' => ['view', 'create', 'update', 'delete'],
            'policies' => ['view', 'create', 'update', 'publish', 'archive', 'assign', 'acknowledge', 'view_acknowledgements', 'export_acknowledgements'],
            'leave_types' => ['view', 'create', 'update', 'delete'],
            'leave_balances' => ['view', 'create', 'update', 'adjust', 'view_all'],
            'leave' => ['view', 'request', 'approve', 'reject', 'cancel', 'view_all', 'view_team'],
            // Checkpoint 33 — Onboarding & Offboarding Foundation. One
            // generic permission set for both process types (onboarding/
            // offboarding are just a `type` value on the same resource,
            // not separately-permissioned resources).
            'lifecycle' => ['view', 'create', 'update', 'delete', 'assign_task', 'complete_task'],
            'announcements' => ['view', 'create', 'publish'],
            'audit' => ['view', 'export'],
            // Checkpoint 34 — HR Documents & Letter Generation Foundation.
            // .create is seeded for forward compatibility but not wired to
            // a route yet this checkpoint — the only write path is
            // generation (.generate), which both creates and renders in
            // one step. Same "seeded ahead of use" posture as audit.export.
            // Checkpoint 36 — .publish added for template version
            // publishing (mirrors policies.publish alongside
            // policies.update); version list/create/edit/archive reuse
            // the existing view/update/delete keys — a version is the
            // template's own history, not a separate-trust resource.
            'hr_document_templates' => ['view', 'create', 'update', 'delete', 'publish'],
            // Checkpoint 37 — .submit/.approve/.reject added for the
            // approval workflow. Split deliberately (not folded into
            // .update) so HR Officer can generate/submit without ever
            // being able to self-approve.
            'hr_generated_documents' => ['view', 'create', 'update', 'delete', 'generate', 'submit', 'approve', 'reject'],
            // Checkpoint 39 — Recruitment & Applicant Tracking Foundation.
            // Split permissions (not one generic 'recruitment' key,
            // unlike lifecycle above) since job openings and applications
            // have different natural owners in practice and the split
            // costs nothing. .update_stage/.add_note/
            // .mark_ready_for_conversion are their own keys — not folded
            // into .update — so a role can move the pipeline forward or
            // add notes without ever holding general edit/delete rights,
            // same "narrow write actions" reasoning as
            // hr_generated_documents.submit/.approve/.reject.
            'job_openings' => ['view', 'create', 'update', 'delete'],
            'job_applications' => ['view', 'create', 'update', 'delete', 'update_stage', 'add_note', 'mark_ready_for_conversion'],
        ];

        foreach ($tenantPermissions as $category => $actions) {
            foreach ($actions as $action) {
                Permission::query()->updateOrCreate(
                    ['key' => "{$category}.{$action}"],
                    ['category' => $category, 'is_platform_permission' => false],
                );
            }
        }

        $platformPermissions = [
            'platform.tenants.view',
            'platform.tenants.create',
            'platform.tenants.update',
            'platform.tenants.disable',
            'platform.users.view',
            'platform.system.view',
        ];

        foreach ($platformPermissions as $key) {
            Permission::query()->updateOrCreate(
                ['key' => $key],
                ['category' => 'platform', 'is_platform_permission' => true],
            );
        }
    }
}
