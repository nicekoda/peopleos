<?php

namespace App\Http\Controllers;

use App\Models\LifecycleTaskTemplate;
use App\Models\Tenant;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Thin Inertia page routes (Checkpoint 42), same pattern as every other
 * admin-lookup module — no template data is ever passed as a page prop.
 * Each page fetches the actual record(s) client-side from
 * /api/v1/lifecycle-task-templates.
 */
class LifecycleTaskTemplateUiController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Settings/LifecycleTaskTemplates/Index');
    }

    public function create(): Response
    {
        return Inertia::render('Settings/LifecycleTaskTemplates/Create');
    }

    public function edit(LifecycleTaskTemplate $lifecycleTaskTemplate): Response
    {
        $this->ensureBelongsToCurrentTenant($lifecycleTaskTemplate);

        return Inertia::render('Settings/LifecycleTaskTemplates/Edit', ['lifecycleTaskTemplateId' => $lifecycleTaskTemplate->id]);
    }

    protected function ensureBelongsToCurrentTenant(LifecycleTaskTemplate $lifecycleTaskTemplate): void
    {
        abort_unless($lifecycleTaskTemplate->tenant_id === app(Tenant::class)->id, 404);
    }
}
