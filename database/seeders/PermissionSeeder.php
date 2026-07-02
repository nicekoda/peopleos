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
            'tenant' => ['view', 'update', 'settings.view', 'settings.update'],
            'users' => ['view', 'create', 'update', 'deactivate', 'assign_role'],
            'roles' => ['view', 'create', 'update', 'delete'],
            'permissions' => ['view', 'assign', 'grant_direct', 'revoke_direct'],
            'employees' => ['view', 'create', 'update', 'delete', 'view_sensitive', 'export'],
            'documents' => ['view', 'upload', 'download', 'delete', 'approve', 'view_sensitive'],
            'document_categories' => ['view', 'create', 'update', 'delete'],
            'leave' => ['view', 'request', 'approve', 'reject'],
            'announcements' => ['view', 'create', 'publish'],
            'audit' => ['view', 'export'],
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
