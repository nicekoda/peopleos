<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class UserSeeder extends Seeder
{
    /**
     * Seed local demo users and assign their roles.
     *
     * "Tenant Admin" / "HR Manager" / "Employee" now correspond to real
     * seeded roles (see RoleSeeder) with real permission sets, not just
     * descriptive names.
     *
     * Password comes from SEED_USER_PASSWORD (set in .env, never
     * committed) — see docs/security.md for local demo credentials.
     *
     * tenant_id / role assignments are set explicitly throughout, since
     * DatabaseSeeder's WithoutModelEvents disables the User/Role
     * saving-guard events during seeding (the assignRole()/grantPermission()
     * method guards still run — see docs/security.md).
     */
    public function run(): void
    {
        $password = Hash::make(env('SEED_USER_PASSWORD', 'password'));

        $superAdmin = User::query()->updateOrCreate(
            ['email' => 'super.admin@peopleos.test'],
            [
                'name' => 'Platform Super Admin',
                'password' => $password,
                'tenant_id' => null,
                'is_platform_admin' => true,
                'status' => User::STATUS_ACTIVE,
                'email_verified_at' => now(),
            ],
        );

        if ($platformRole = Role::query()->where('slug', 'platform-super-admin')->where('tenant_id', null)->first()) {
            $superAdmin->assignRole($platformRole);
        }

        $tenants = Tenant::query()->whereIn('subdomain', ['uesl', 'airpeace', 'ibom'])->get()->keyBy('subdomain');

        foreach ($tenants as $subdomain => $tenant) {
            $admin = User::query()->updateOrCreate(
                ['email' => "admin@{$subdomain}.peopleos.test"],
                [
                    'name' => "{$tenant->name} Admin",
                    'password' => $password,
                    'tenant_id' => $tenant->id,
                    'is_platform_admin' => false,
                    'status' => User::STATUS_ACTIVE,
                    'email_verified_at' => now(),
                ],
            );

            if ($tenantAdminRole = Role::query()->where('slug', 'tenant-admin')->where('tenant_id', $tenant->id)->first()) {
                $admin->assignRole($tenantAdminRole);
            }
        }

        if ($uesl = $tenants->get('uesl')) {
            $hrManager = User::query()->updateOrCreate(
                ['email' => 'hr.manager@uesl.peopleos.test'],
                [
                    'name' => 'UESL HR Manager',
                    'password' => $password,
                    'tenant_id' => $uesl->id,
                    'is_platform_admin' => false,
                    'status' => User::STATUS_ACTIVE,
                    'email_verified_at' => now(),
                ],
            );

            if ($hrManagerRole = Role::query()->where('slug', 'hr-manager')->where('tenant_id', $uesl->id)->first()) {
                $hrManager->assignRole($hrManagerRole);
            }

            $employee = User::query()->updateOrCreate(
                ['email' => 'employee@uesl.peopleos.test'],
                [
                    'name' => 'UESL Employee',
                    'password' => $password,
                    'tenant_id' => $uesl->id,
                    'is_platform_admin' => false,
                    'status' => User::STATUS_ACTIVE,
                    'email_verified_at' => now(),
                ],
            );

            if ($employeeRole = Role::query()->where('slug', 'employee')->where('tenant_id', $uesl->id)->first()) {
                $employee->assignRole($employeeRole);
            }

            // Example of the direct-grant mechanism: this employee gets
            // documents.download beyond what the Employee role normally
            // includes, granted by their HR Manager.
            if ($downloadPermission = Permission::query()->where('key', 'documents.download')->first()) {
                $employee->grantPermission($downloadPermission, $hrManager, 'Needs to download signed onboarding documents.');
            }

            // Checkpoint 26: HR Officer / Line Manager / Auditor demo
            // users, so every role in the live smoke test has a real
            // seeded login instead of an ad-hoc tinker-created one that
            // gets wiped on the next migrate:fresh --seed.
            $hrOfficer = User::query()->updateOrCreate(
                ['email' => 'hr.officer@uesl.peopleos.test'],
                [
                    'name' => 'UESL HR Officer',
                    'password' => $password,
                    'tenant_id' => $uesl->id,
                    'is_platform_admin' => false,
                    'status' => User::STATUS_ACTIVE,
                    'email_verified_at' => now(),
                ],
            );

            if ($hrOfficerRole = Role::query()->where('slug', 'hr-officer')->where('tenant_id', $uesl->id)->first()) {
                $hrOfficer->assignRole($hrOfficerRole);
            }

            $lineManager = User::query()->updateOrCreate(
                ['email' => 'line.manager@uesl.peopleos.test'],
                [
                    'name' => 'UESL Line Manager',
                    'password' => $password,
                    'tenant_id' => $uesl->id,
                    'is_platform_admin' => false,
                    'status' => User::STATUS_ACTIVE,
                    'email_verified_at' => now(),
                ],
            );

            if ($lineManagerRole = Role::query()->where('slug', 'line-manager')->where('tenant_id', $uesl->id)->first()) {
                $lineManager->assignRole($lineManagerRole);
            }

            $auditor = User::query()->updateOrCreate(
                ['email' => 'auditor@uesl.peopleos.test'],
                [
                    'name' => 'UESL Auditor',
                    'password' => $password,
                    'tenant_id' => $uesl->id,
                    'is_platform_admin' => false,
                    'status' => User::STATUS_ACTIVE,
                    'email_verified_at' => now(),
                ],
            );

            if ($auditorRole = Role::query()->where('slug', 'auditor')->where('tenant_id', $uesl->id)->first()) {
                $auditor->assignRole($auditorRole);
            }
        }
    }
}
