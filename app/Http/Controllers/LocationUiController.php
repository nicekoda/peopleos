<?php

namespace App\Http\Controllers;

use App\Models\Location;
use App\Models\Tenant;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Thin Inertia page routes (Checkpoint 32) — see DepartmentUiController
 * for the full reasoning, identical here.
 */
class LocationUiController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Settings/Locations/Index');
    }

    public function create(): Response
    {
        return Inertia::render('Settings/Locations/Create');
    }

    public function edit(Location $location): Response
    {
        $this->ensureBelongsToCurrentTenant($location);

        return Inertia::render('Settings/Locations/Edit', ['locationId' => $location->id]);
    }

    protected function ensureBelongsToCurrentTenant(Location $location): void
    {
        abort_unless($location->tenant_id === app(Tenant::class)->id, 404);
    }
}
