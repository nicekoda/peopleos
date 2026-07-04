<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\RoleResource;
use App\Models\Role;
use App\Models\Tenant;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Role, like User, does not use BelongsToTenant (see docs/security.md)
 * — the manual where('tenant_id', ...) + where('is_platform_role', false)
 * filter below is the only tenant/scope boundary here (Refinement 1),
 * not defense-in-depth on top of a global scope.
 */
class RoleController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $roles = Role::query()
            ->where('tenant_id', app(Tenant::class)->id)
            ->where('is_platform_role', false)
            ->withCount('permissions')
            ->orderBy('name')
            ->paginate();

        return RoleResource::collection($roles);
    }
}
