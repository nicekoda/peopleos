<?php

namespace App\Http\Controllers;

use App\Models\Position;
use App\Models\Tenant;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Thin Inertia page routes (Checkpoint 32) — see DepartmentUiController
 * for the full reasoning, identical here.
 */
class PositionUiController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Settings/Positions/Index');
    }

    public function create(): Response
    {
        return Inertia::render('Settings/Positions/Create');
    }

    public function edit(Position $position): Response
    {
        $this->ensureBelongsToCurrentTenant($position);

        return Inertia::render('Settings/Positions/Edit', ['positionId' => $position->id]);
    }

    protected function ensureBelongsToCurrentTenant(Position $position): void
    {
        abort_unless($position->tenant_id === app(Tenant::class)->id, 404);
    }
}
