<?php

namespace App\Providers;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
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

        // Checkpoint 44 — the default ResetPassword notification builds
        // its URL via route('password.reset', ...), which resolves
        // against whatever host happens to be current when the
        // notification is built — never reliably the *user's own*
        // tenant subdomain (User has no BelongsToTenant scope/relation
        // to a "current request host" at all). A tenant user's reset
        // link must always point back to their own
        // {subdomain}.{base_domain}, never the base domain or another
        // tenant's subdomain, regardless of which host the /forgot-password
        // request happened to arrive on (ForgotPasswordRequest already
        // refuses to send one at all in that mismatched case — this is
        // what makes the *matched* case actually land somewhere useful).
        ResetPassword::createUrlUsing(function (User $user, string $token) {
            $baseDomain = config('tenancy.base_domain');
            $host = $user->is_platform_admin || ! $user->tenant
                ? $baseDomain
                : $user->tenant->subdomain.'.'.$baseDomain;
            $scheme = str_starts_with(config('app.url'), 'https') ? 'https' : 'http';

            return "{$scheme}://{$host}/reset-password/{$token}?".http_build_query(['email' => $user->email]);
        });
    }
}
