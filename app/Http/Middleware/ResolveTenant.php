<?php

namespace App\Http\Middleware;

use App\Models\Tenant;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class ResolveTenant
{
    /**
     * Handle an incoming request.
     *
     * Resolves the current tenant from the request's subdomain and binds
     * it into the container. Requests to the bare base domain, or to a
     * reserved subdomain (www, api, admin), are treated as platform-level
     * requests with no tenant bound.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $baseDomain = config('tenancy.base_domain');
        $host = $request->getHost();

        if ($host === $baseDomain) {
            return $next($request);
        }

        if (! str_ends_with($host, ".{$baseDomain}")) {
            throw new NotFoundHttpException;
        }

        $subdomain = substr($host, 0, -(strlen($baseDomain) + 1));

        if (in_array($subdomain, config('tenancy.reserved_subdomains'), true)) {
            return $next($request);
        }

        $tenant = Tenant::query()->where('subdomain', $subdomain)->first();

        if (! $tenant) {
            throw new NotFoundHttpException;
        }

        if (! $tenant->isActive()) {
            abort(403, 'This tenant is not currently active.');
        }

        app()->instance(Tenant::class, $tenant);
        $request->attributes->set('tenant', $tenant);

        return $next($request);
    }
}
