<?php

namespace App\Http\Controllers;

use App\Models\LifecycleProcess;
use App\Models\LifecycleTask;
use App\Models\Tenant;
use App\Services\LifecycleVisibilityService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Thin Inertia page routes (Checkpoint 33), same pattern as every other
 * module — no process/task data is ever passed as a page prop, only IDs.
 * Each page fetches the actual record(s) client-side from the existing
 * /api/v1/lifecycle-processes and /api/v1/lifecycle-tasks endpoints.
 * Routes are generic (/lifecycle/...) but page components label each
 * process by its `type` (Onboarding/Offboarding) once loaded.
 */
class LifecycleUiController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Lifecycle/Index');
    }

    public function create(): Response
    {
        return Inertia::render('Lifecycle/Create');
    }

    public function show(Request $request, LifecycleProcess $lifecycleProcess): Response
    {
        $this->ensureBelongsToCurrentTenant($lifecycleProcess);
        $this->ensureCanAccess($request, $lifecycleProcess);

        return Inertia::render('Lifecycle/Show', ['processId' => $lifecycleProcess->id]);
    }

    public function edit(LifecycleProcess $lifecycleProcess): Response
    {
        $this->ensureBelongsToCurrentTenant($lifecycleProcess);

        return Inertia::render('Lifecycle/Edit', ['processId' => $lifecycleProcess->id]);
    }

    public function taskCreate(LifecycleProcess $lifecycleProcess): Response
    {
        $this->ensureBelongsToCurrentTenant($lifecycleProcess);

        return Inertia::render('Lifecycle/TaskCreate', ['processId' => $lifecycleProcess->id]);
    }

    public function taskEdit(Request $request, LifecycleTask $lifecycleTask): Response
    {
        abort_unless($lifecycleTask->tenant_id === app(Tenant::class)->id, 404);
        abort_unless(app(LifecycleVisibilityService::class)->canAccessTask($request->user(), $lifecycleTask), 404);

        return Inertia::render('Lifecycle/TaskEdit', ['taskId' => $lifecycleTask->id, 'processId' => $lifecycleTask->process_id]);
    }

    protected function ensureBelongsToCurrentTenant(LifecycleProcess $process): void
    {
        abort_unless($process->tenant_id === app(Tenant::class)->id, 404);
    }

    protected function ensureCanAccess(Request $request, LifecycleProcess $process): void
    {
        abort_unless(app(LifecycleVisibilityService::class)->canAccessProcess($request->user(), $process), 404);
    }
}
