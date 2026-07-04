<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Route;

/**
 * Formalizes a check that has been run by hand, from a scratch script,
 * before every checkpoint since roughly Checkpoint 13: every
 * `auth`-protected route must also carry `tenant.matches` (Checkpoint 6),
 * since that middleware is applied per-route, not globally (see
 * docs/security.md's "Known Limitations" — there is still no
 * higher-level enforcement of this beyond this command and manual
 * review). Excludes routes that are legitimately not tenant-scoped
 * (login/logout, the root redirect, the health check, Laravel's built-in
 * storage/sanctum routes).
 *
 * This is read-only — it inspects the already-registered route table
 * and prints a report. It changes nothing and is safe to run at any
 * time, including in CI or as a pre-deploy check.
 */
#[Signature('route:audit-tenant-scoping')]
#[Description('Audit every registered route: any auth-protected route must also carry tenant.matches middleware.')]
class AuditTenantRouteScoping extends Command
{
    private const EXCLUDED_PREFIXES = ['login', 'logout', 'up', 'sanctum', 'storage'];

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $checked = 0;
        $missing = [];

        foreach (Route::getRoutes() as $route) {
            $uri = $route->uri();

            if ($uri === '/' || $this->hasExcludedPrefix($uri)) {
                continue;
            }

            $middleware = $route->gatherMiddleware();
            $hasAuth = in_array('auth', $middleware, true) || $this->hasMiddlewareStartingWith($middleware, 'auth:');
            $hasTenantMatch = in_array('tenant.matches', $middleware, true);

            if (! $hasAuth) {
                continue;
            }

            $checked++;

            if (! $hasTenantMatch) {
                $missing[] = sprintf('%s %s', implode('|', $route->methods()), $uri);
            }
        }

        $this->info("Total auth-protected routes checked: {$checked}");
        $this->info('Missing tenant.matches despite auth: '.count($missing));

        foreach ($missing as $line) {
            $this->line("  {$line}");
        }

        return $missing === [] ? self::SUCCESS : self::FAILURE;
    }

    private function hasExcludedPrefix(string $uri): bool
    {
        foreach (self::EXCLUDED_PREFIXES as $prefix) {
            if ($uri === $prefix || str_starts_with($uri, "{$prefix}/")) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<string>  $middleware
     */
    private function hasMiddlewareStartingWith(array $middleware, string $prefix): bool
    {
        foreach ($middleware as $entry) {
            if (str_starts_with($entry, $prefix)) {
                return true;
            }
        }

        return false;
    }
}
