<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Checkpoint 27 — formalizes a check that used to be a scratch script
 * re-created by hand before every checkpoint (see docs/testing.md). Now
 * a real, committed regression guard: if a future checkpoint adds an
 * `auth`-protected route without `tenant.matches`, this test fails
 * immediately instead of relying on someone remembering to re-run a
 * script that only ever lived outside the repo.
 */
class AuditTenantRouteScopingCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_auth_protected_route_carries_tenant_matches(): void
    {
        $this->artisan('route:audit-tenant-scoping')
            ->assertExitCode(0);
    }
}
