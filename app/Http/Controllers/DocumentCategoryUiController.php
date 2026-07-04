<?php

namespace App\Http\Controllers;

use App\Models\DocumentCategory;
use App\Models\Tenant;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Thin Inertia page routes (Checkpoint 25), same pattern as every other
 * module — no document category data is ever passed as a page prop.
 * Each page fetches the actual record(s) client-side from the existing
 * /api/v1/document-categories endpoints (Checkpoint 9). DocumentCategory
 * already uses BelongsToTenant, so this is the standard two-layer
 * tenant-isolation pattern (global scope + explicit check), not the
 * "manual filtering is the primary defense" situation Users/Roles/Audit
 * Logs needed (Checkpoints 23/24).
 */
class DocumentCategoryUiController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Settings/DocumentCategories/Index');
    }

    public function create(): Response
    {
        return Inertia::render('Settings/DocumentCategories/Create');
    }

    public function edit(DocumentCategory $documentCategory): Response
    {
        $this->ensureBelongsToCurrentTenant($documentCategory);

        return Inertia::render('Settings/DocumentCategories/Edit', ['documentCategoryId' => $documentCategory->id]);
    }

    protected function ensureBelongsToCurrentTenant(DocumentCategory $documentCategory): void
    {
        abort_unless($documentCategory->tenant_id === app(Tenant::class)->id, 404);
    }
}
