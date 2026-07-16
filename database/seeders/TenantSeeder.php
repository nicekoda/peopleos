<?php

namespace Database\Seeders;

use App\Models\Tenant;
use App\Services\TenantModuleService;
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

        // Checkpoint 47 — provisionDefaults() is called explicitly here,
        // not left to Tenant's own `created` model-event hook: this
        // whole seeder runs under DatabaseSeeder's WithoutModelEvents
        // (see docs/security.md), which suppresses that hook entirely.
        // Found by actually testing migrate:fresh --seed rather than
        // assuming the hook alone was sufficient — it produced zero
        // tenant_modules rows until this explicit call was added.
        $moduleService = app(TenantModuleService::class);

        foreach ($tenants as $tenant) {
            $tenantModel = Tenant::query()->updateOrCreate(
                ['subdomain' => $tenant['subdomain']],
                ['name' => $tenant['name'], 'status' => Tenant::STATUS_ACTIVE],
            );

            $moduleService->provisionDefaults($tenantModel);
        }
    }
}
