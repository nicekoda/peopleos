<?php

namespace App\Providers;

use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Makes Laravel's native $user->can('employees.view') / @can /
        // authorize() respect the same RBAC permission check used by the
        // `permission:` middleware, without duplicating logic in policies.
        // Returning null (not false) when the check fails lets any actual
        // policy/gate defined later for the same ability still run.
        Gate::before(function (User $user, string $ability) {
            return $user->hasPermission($ability) ?: null;
        });
    }
}
