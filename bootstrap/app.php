<?php

use App\Http\Middleware\ResolveTenant;
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
        $middleware->appendToGroup('web', ResolveTenant::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // No login/logout UI exists yet (backend-only auth foundation),
        // so these endpoints must always respond JSON, not redirect back
        // to a nonexistent form on validation failure.
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->is('login') || $request->is('logout'),
        );
    })->create();
