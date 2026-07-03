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
            ['name' => 'Platform Super Admin', 'is_platform_role' => true],
        );

        Permission::query()->where('is_platform_permission', true)->get()
            ->each(fn (Permission $permission) => $role->givePermissionTo($permission));
    }

    private function seedTenantRoles(Tenant $tenant): void
    {
        $roles = collect(self::TENANT_ROLE_NAMES)->mapWithKeys(function (string $name) use ($tenant) {
            $role = Role::query()->updateOrCreate(
                ['slug' => Str::slug($name), 'tenant_id' => $tenant->id],
                ['name' => $name, 'is_platform_role' => false],
            );

            return [$name => $role];
        });

        $this->grantByKeys($roles['Tenant Admin'], Permission::query()->where('is_platform_permission', false)->pluck('key')->all());

        $this->grantByKeys($roles['HR Manager'], [
            'employees.view', 'employees.create', 'employees.update', 'employees.view_sensitive', 'employees.export',
            'documents.view', 'documents.upload', 'documents.download', 'documents.approve',
            'leave.view', 'leave.approve', 'leave.reject',
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
        ]);

        $this->grantByKeys($roles['Employee'], [
            'employees.view',
            'documents.view', 'documents.upload',
            'leave.view', 'leave.request',
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
            'policies.view', 'policies.create', 'policies.update',
            'policies.assign', 'policies.view_acknowledgements',
        ]);

        $this->grantByKeys($roles['Auditor'], [
            'policies.view', 'policies.view_acknowledgements',
        ]);

        // Remaining roles (HR Director, Line Manager, etc.) are
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
