<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\PermissionResource;
use App\Models\Permission;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Read-only (Checkpoint 23, Refinement 8) — no create/update/delete, no
 * direct permission grants. Permission definitions are global, not
 * tenant-owned (no tenant_id column at all — see docs/architecture.md),
 * so the only scoping needed is excluding platform-only permission
 * definitions from a tenant user's view.
 */
class PermissionController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $permissions = Permission::query()
            ->where('is_platform_permission', false)
            ->orderBy('category')
            ->orderBy('key')
            ->paginate();

        return PermissionResource::collection($permissions);
    }
}
