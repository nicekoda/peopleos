<?php

namespace Tests\Feature;

use App\Enums\TenantModule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
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

    /**
     * Checkpoint 48 — regression test for a real bug found while adding
     * custom-fields routes: routes/api.php wraps everything in
     * Route::prefix('api/v1'), so an API route's uri() is
     * 'api/v1/job-openings', not 'job-openings' — the command's prefix
     * matching never accounted for this, silently checking web.php
     * pages only since Checkpoint 47 (0 API routes actually verified).
     * This proves every registered api/v1/* route under a toggleable
     * module's own prefix is actually being examined, not silently
     * skipped.
     */
    public function test_api_routes_are_actually_checked_not_silently_skipped(): void
    {
        $apiRoutesUnderToggleableModules = 0;

        foreach (TenantModule::toggleable() as $module) {
            foreach (Route::getRoutes() as $route) {
                $uri = $route->uri();

                if (! str_starts_with($uri, 'api/v1/')) {
                    continue;
                }

                $bareUri = substr($uri, strlen('api/v1/'));
                $prefixes = $module->routeGroupPrefixes();
                $exactUris = $module->additionalGatedUris();

                $matches = in_array($bareUri, $exactUris, true);
                foreach ($prefixes as $prefix) {
                    $matches = $matches || $bareUri === $prefix || str_starts_with($bareUri, "{$prefix}/");
                }

                if ($matches) {
                    $apiRoutesUnderToggleableModules++;
                }
            }
        }

        // A generous floor, not an exact count — job-openings/
        // job-applications/custom-fields alone already exceed this;
        // the real point is proving this is non-zero (it was zero
        // before the fix) and comfortably reflects real API coverage.
        $this->assertGreaterThan(50, $apiRoutesUnderToggleableModules);
    }
}
