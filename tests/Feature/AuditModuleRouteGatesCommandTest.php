<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Checkpoint 47 — mirrors AuditTenantRouteScopingCommandTest's exact
 * shape: a real, committed regression guard so a future checkpoint that
 * adds a route to a toggleable module's route group without its
 * module:{key} middleware fails immediately, instead of relying on
 * someone remembering to check.
 */
class AuditModuleRouteGatesCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_toggleable_module_route_carries_its_module_gate(): void
    {
        $this->artisan('route:audit-module-gates')
            ->assertExitCode(0);
    }
}
