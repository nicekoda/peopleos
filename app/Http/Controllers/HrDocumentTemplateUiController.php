<?php

namespace App\Http\Controllers;

use App\Models\HrDocumentTemplate;
use App\Models\Tenant;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Thin Inertia page routes (Checkpoint 34), same pattern as every other
 * module — no template data is ever passed as a page prop beyond an ID.
 * Each page fetches the actual record(s) client-side from the existing
 * /api/v1/hr-document-templates endpoints. HrDocumentTemplate already
 * uses BelongsToTenant, so this is the standard two-layer tenant
 * isolation pattern (global scope + explicit check), same as
 * DocumentCategoryUiController.
 */
class HrDocumentTemplateUiController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Settings/HrDocumentTemplates/Index');
    }

    public function create(): Response
    {
        return Inertia::render('Settings/HrDocumentTemplates/Create');
    }

    public function edit(HrDocumentTemplate $hrDocumentTemplate): Response
    {
        $this->ensureBelongsToCurrentTenant($hrDocumentTemplate);

        return Inertia::render('Settings/HrDocumentTemplates/Edit', ['hrDocumentTemplateId' => $hrDocumentTemplate->id]);
    }

    protected function ensureBelongsToCurrentTenant(HrDocumentTemplate $hrDocumentTemplate): void
    {
        abort_unless($hrDocumentTemplate->tenant_id === app(Tenant::class)->id, 404);
    }
}
