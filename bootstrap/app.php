<?php

use App\Console\Commands\SendLifecycleTaskDigest;
use App\Http\Middleware\EnsureModuleEnabled;
use App\Http\Middleware\EnsurePermission;
use App\Http\Middleware\EnsureTenantMatchesAuthenticatedUser;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\ResolveTenant;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Must run before SubstituteBindings (route model binding), which
        // is part of Laravel's default 'web' group stack — otherwise a
        // tenant-scoped model's {param} route binding would resolve
        // before any tenant is bound in the container, meaning
        // BelongsToTenant's global scope wouldn't be active yet for that
        // lookup. prependToGroup, not appendToGroup.
        $middleware->prependToGroup('web', ResolveTenant::class);
        $middleware->appendToGroup('web', HandleInertiaRequests::class);
        $middleware->alias([
            'permission' => EnsurePermission::class,
            'tenant.matches' => EnsureTenantMatchesAuthenticatedUser::class,
            'module' => EnsureModuleEnabled::class,
        ]);

        // Checkpoint 16: a real 'login' named route now exists (the
        // Inertia login page), so an unauthenticated non-JSON request to
        // any 'auth'-protected route redirects there instead of crashing.
        // JSON-expecting requests are unaffected — Laravel's Authenticate
        // middleware only consults this closure when
        // !$request->expectsJson(), always returning a plain 401 JSON
        // response otherwise (see every existing getJson()/postJson()
        // "unauthenticated" test across the API suite). Previously
        // fn () => null, back when no login route existed at all
        // (Checkpoint 7) — see docs/security.md.
        $middleware->redirectGuestsTo(fn () => route('login'));
    })
    // Checkpoint 45 — the app's first scheduled task. Every prior
    // checkpoint left this closure absent entirely (see
    // docs/deployment.md §6: "no scheduler infrastructure exists;
    // revisit once a genuinely scheduled task is actually built" — this
    // is that moment). Requires a real cron entry running
    // `php artisan schedule:run` every minute in production; see
    // docs/deployment.md for the exact crontab line. dailyAt() is in the
    // application's configured timezone (config('app.timezone')) — there
    // is no per-tenant timezone concept yet, so every tenant's digest
    // fires at the same wall-clock moment.
    ->withSchedule(function (Schedule $schedule): void {
        $schedule->command(SendLifecycleTaskDigest::class)->dailyAt('07:00');
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // api/* always gets JSON, full stop — it's an API surface
        // regardless of what a caller's Accept header happens to say.
        // Everything else (including /login, /logout, now serving both
        // the JSON API contract and real Inertia browser/form requests)
        // defers to normal content negotiation: a request that actually
        // wants JSON (postJson()/getJson() in tests, or a genuine API
        // client) still gets it; a real browser/Inertia request gets
        // Laravel's normal redirect-back-with-errors behavior instead,
        // which Inertia's client renders as form validation errors. See
        // docs/security.md.
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
