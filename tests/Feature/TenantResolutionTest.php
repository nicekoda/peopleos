<?php

namespace Tests\Feature;

use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TenantResolutionTest extends TestCase
{
    use RefreshDatabase;

    public function test_request_to_known_subdomain_resolves_tenant(): void
    {
        $tenant = Tenant::factory()->create(['subdomain' => 'acme']);

        $this->get('http://acme.'.config('tenancy.base_domain').'/')->assertOk();

        $this->assertTrue(app()->bound(Tenant::class));
        $this->assertTrue(app(Tenant::class)->is($tenant));
    }

    public function test_request_to_base_domain_resolves_no_tenant(): void
    {
        $this->get('http://'.config('tenancy.base_domain').'/')->assertOk();

        $this->assertFalse(app()->bound(Tenant::class));
    }

    public function test_request_to_unknown_subdomain_returns_404(): void
    {
        $this->get('http://ghost.'.config('tenancy.base_domain').'/')->assertNotFound();
    }

    public function test_request_to_inactive_tenant_returns_403(): void
    {
        Tenant::factory()->create([
            'subdomain' => 'suspended-co',
            'status' => Tenant::STATUS_SUSPENDED,
        ]);

        $this->get('http://suspended-co.'.config('tenancy.base_domain').'/')->assertForbidden();
    }

    public function test_request_to_reserved_subdomain_resolves_no_tenant(): void
    {
        $this->get('http://www.'.config('tenancy.base_domain').'/')->assertOk();

        $this->assertFalse(app()->bound(Tenant::class));
    }
}
