<?php

namespace Database\Seeders;

use App\Models\Tenant;
use Illuminate\Database\Seeder;

class TenantSeeder extends Seeder
{
    /**
     * Seed demo tenants matching the local subdomains already configured
     * in the hosts file. Names are placeholders — rename directly in the
     * tenants table until tenant management tooling exists.
     */
    public function run(): void
    {
        $tenants = [
            ['name' => 'UESL', 'subdomain' => 'uesl'],
            ['name' => 'Air Peace', 'subdomain' => 'airpeace'],
            ['name' => 'Ibom Air', 'subdomain' => 'ibom'],
        ];

        foreach ($tenants as $tenant) {
            Tenant::query()->updateOrCreate(
                ['subdomain' => $tenant['subdomain']],
                ['name' => $tenant['name'], 'status' => Tenant::STATUS_ACTIVE],
            );
        }
    }
}
