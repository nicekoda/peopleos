<?php

namespace Database\Seeders;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class UserSeeder extends Seeder
{
    /**
     * Seed local demo users: one platform super admin, a tenant admin for
     * each demo tenant, and an HR manager + employee for the first tenant.
     *
     * "Tenant Admin" / "HR Manager" / "Employee" are descriptive labels
     * only at this checkpoint — no roles/permissions table exists yet
     * (RBAC is a later checkpoint), so these are plain users for exercising
     * tenant-aware login, not role-assigned accounts.
     *
     * Password comes from SEED_USER_PASSWORD (set in .env, never
     * committed) — see docs/security.md for local demo credentials.
     */
    public function run(): void
    {
        $password = Hash::make(env('SEED_USER_PASSWORD', 'password'));

        User::query()->updateOrCreate(
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

        $tenants = Tenant::query()->whereIn('subdomain', ['uesl', 'airpeace', 'ibom'])->get()->keyBy('subdomain');

        foreach ($tenants as $subdomain => $tenant) {
            User::query()->updateOrCreate(
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
        }

        if ($uesl = $tenants->get('uesl')) {
            User::query()->updateOrCreate(
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

            User::query()->updateOrCreate(
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
        }
    }
}
