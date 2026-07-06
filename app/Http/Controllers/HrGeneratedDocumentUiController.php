<?php

namespace App\Http\Controllers;

use App\Models\HrGeneratedDocument;
use App\Models\Tenant;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Thin Inertia page routes (Checkpoint 34) — top-level, not nested under
 * /employees/{employee}, matching the /lifecycle?employeeId= convention
 * (LifecycleUiController) rather than the /employees/{employee}/documents
 * nesting: a generated-document list spans employees and the generate
 * form itself picks the employee, so a single flat resource is simpler.
 * No document data is ever passed as a page prop beyond an ID.
 */
class HrGeneratedDocumentUiController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('HrDocuments/Index');
    }

    public function create(): Response
    {
        return Inertia::render('HrDocuments/Create');
    }

    public function show(HrGeneratedDocument $hrGeneratedDocument): Response
    {
        $this->ensureBelongsToCurrentTenant($hrGeneratedDocument);

        return Inertia::render('HrDocuments/Show', ['hrGeneratedDocumentId' => $hrGeneratedDocument->id]);
    }

    protected function ensureBelongsToCurrentTenant(HrGeneratedDocument $hrGeneratedDocument): void
    {
        abort_unless($hrGeneratedDocument->tenant_id === app(Tenant::class)->id, 404);
    }
}
