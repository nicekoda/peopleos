<?php

namespace App\Http\Controllers;

use App\Models\Policy;
use App\Models\Tenant;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Thin Inertia page routes, same pattern as every other module's UI
 * controller (Checkpoints 17/18/19) — no policy data is ever passed as
 * a page prop. Each page fetches the actual record(s) client-side from
 * the existing /api/v1/policies endpoints (Checkpoint 10), plus the new
 * read-only /api/v1/policies/{policy}/versions (Checkpoint 20). See
 * docs/architecture.md.
 */
class PolicyUiController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Policies/Index');
    }

    public function create(): Response
    {
        return Inertia::render('Policies/Create');
    }

    public function show(Policy $policy): Response
    {
        $this->ensureBelongsToCurrentTenant($policy);

        return Inertia::render('Policies/Show', ['policyId' => $policy->id]);
    }

    public function edit(Policy $policy): Response
    {
        $this->ensureBelongsToCurrentTenant($policy);

        return Inertia::render('Policies/Edit', ['policyId' => $policy->id]);
    }

    public function createVersion(Policy $policy): Response
    {
        $this->ensureBelongsToCurrentTenant($policy);

        return Inertia::render('Policies/VersionCreate', ['policyId' => $policy->id]);
    }

    public function assign(Policy $policy): Response
    {
        $this->ensureBelongsToCurrentTenant($policy);

        return Inertia::render('Policies/Assign', ['policyId' => $policy->id]);
    }

    public function acknowledgements(Policy $policy): Response
    {
        $this->ensureBelongsToCurrentTenant($policy);

        return Inertia::render('Policies/Acknowledgements', ['policyId' => $policy->id]);
    }

    protected function ensureBelongsToCurrentTenant(Policy $policy): void
    {
        abort_unless($policy->tenant_id === app(Tenant::class)->id, 404);
    }
}
