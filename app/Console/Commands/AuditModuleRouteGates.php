<?php

namespace App\Console\Commands;

use App\Enums\TenantModule;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Route;

/**
 * Checkpoint 47 — mirrors AuditTenantRouteScoping's exact shape (that
 * command checks every auth-protected route carries tenant.matches;
 * this one checks every route belonging to a toggleable module carries
 * that module's own `module:{key}` middleware). Read-only, safe to run
 * any time, including in CI.
 *
 * "Belonging to a module" is determined the same way
 * TenantModule::routeGroupPrefixes()/additionalGatedUris() themselves
 * define it — this command doesn't re-derive that mapping, it just
 * verifies routes/api.php and routes/web.php actually applied the gate
 * the registry says they should.
 */
#[Signature('route:audit-module-gates')]
#[Description("Audit every registered route: any route belonging to a toggleable module must carry that module's own module:{key} middleware.")]
class AuditModuleRouteGates extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $checked = 0;
        $missing = [];

        foreach (TenantModule::toggleable() as $module) {
            $prefixes = $module->routeGroupPrefixes();
            $exactUris = $module->additionalGatedUris();

            foreach (Route::getRoutes() as $route) {
                $uri = $route->uri();

                if (! $this->belongsToModule($uri, $prefixes, $exactUris)) {
                    continue;
                }

                $checked++;
                $middleware = $route->gatherMiddleware();

                if (! in_array("module:{$module->value}", $middleware, true)) {
                    $missing[] = sprintf('%s %s (expected module:%s)', implode('|', $route->methods()), $uri, $module->value);
                }
            }
        }

        $this->info("Total toggleable-module routes checked: {$checked}");
        $this->info('Missing their module:{key} gate: '.count($missing));

        foreach ($missing as $line) {
            $this->line("  {$line}");
        }

        return $missing === [] ? self::SUCCESS : self::FAILURE;
    }

    /**
     * routes/api.php wraps every route in Route::prefix('api/v1'), so
     * $route->uri() for an API route is e.g. 'api/v1/job-openings', not
     * 'job-openings' — while routes/web.php has no such prefix.
     * TenantModule::routeGroupPrefixes()'s bare prefixes are meant to
     * match both (its own docblock: "in both routes/api.php and
     * routes/web.php"), so the leading 'api/v1/' is stripped before
     * comparing. Found and fixed in Checkpoint 48: before this, every
     * api.php route silently never matched any prefix here — this
     * command had been checking web.php pages only since Checkpoint 47,
     * not the API routes it was actually built to guard.
     *
     * @param  list<string>  $prefixes
     * @param  list<string>  $exactUris
     */
    private function belongsToModule(string $uri, array $prefixes, array $exactUris): bool
    {
        $uri = str_starts_with($uri, 'api/v1/') ? substr($uri, strlen('api/v1/')) : $uri;

        if (in_array($uri, $exactUris, true)) {
            return true;
        }

        foreach ($prefixes as $prefix) {
            if ($uri === $prefix || str_starts_with($uri, "{$prefix}/")) {
                return true;
            }
        }

        return false;
    }
}
