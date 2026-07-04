<?php

namespace App\Http\Controllers;

use App\Models\Tenant;
use App\Models\User;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Thin Inertia page routes (Checkpoint 23), same pattern as every other
 * module — no user/role data is ever passed as a page prop. Each page
 * fetches the actual record(s) client-side from the new
 * /api/v1/users|roles|permissions endpoints. Because User does not use
 * BelongsToTenant (see docs/security.md), show() adds the same explicit
 * tenant + platform-admin check the API layer relies on — this is the
 * primary tenant boundary here, not defense-in-depth on top of a scope.
 */
class UsersAccessUiController extends Controller
{
    public function users(): Response
    {
        return Inertia::render('Settings/AccessUsers');
    }

    public function show(User $user): Response
    {
        $this->ensureBelongsToCurrentTenant($user);

        return Inertia::render('Settings/AccessUserShow', ['userId' => $user->id]);
    }

    public function roles(): Response
    {
        return Inertia::render('Settings/AccessRoles');
    }

    protected function ensureBelongsToCurrentTenant(User $user): void
    {
        abort_if($user->is_platform_admin, 404);
        abort_unless($user->tenant_id === app(Tenant::class)->id, 404);
    }
}
