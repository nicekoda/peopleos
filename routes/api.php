<?php

use App\Http\Controllers\Api\V1\DocumentCategoryController;
use App\Http\Controllers\Api\V1\EmployeeController;
use App\Http\Controllers\Api\V1\EmployeeDocumentController;
use App\Http\Controllers\Api\V1\EmployeeUserLinkController;
use App\Http\Controllers\Api\V1\MeController;
use App\Http\Controllers\Api\V1\PolicyController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes (v1)
|--------------------------------------------------------------------------
|
| Registered through routes/web.php, so these run through the 'web'
| middleware group (session, CSRF, ResolveTenant) — there's no separate
| token-based API guard yet (no Sanctum), so this is the same
| authenticated-session model as the rest of the app. Introduce a
| stateless token guard when an external API consumer actually exists.
|
| Middleware order on every route below is deliberate:
|   auth              -> is anyone authenticated at all?
|   tenant.matches    -> does that authenticated user actually belong to
|                        the tenant this subdomain resolved to?
|   permission:{key}  -> does the user have this specific permission?
| Skipping 'tenant.matches' would let a valid session from one tenant
| pass permission checks and reach tenant-scoped queries under a
| different tenant's subdomain — see docs/security.md.
|
*/

Route::middleware(['auth', 'tenant.matches'])->prefix('api/v1')->group(function () {
    Route::get('employees', [EmployeeController::class, 'index'])->middleware('permission:employees.view');
    Route::post('employees', [EmployeeController::class, 'store'])->middleware('permission:employees.create');
    Route::get('employees/{employee}', [EmployeeController::class, 'show'])->middleware('permission:employees.view');
    Route::patch('employees/{employee}', [EmployeeController::class, 'update'])->middleware('permission:employees.update');
    Route::delete('employees/{employee}', [EmployeeController::class, 'destroy'])->middleware('permission:employees.delete');

    Route::get('employees/{employee}/documents', [EmployeeDocumentController::class, 'index'])->middleware('permission:documents.view');
    Route::post('employees/{employee}/documents', [EmployeeDocumentController::class, 'store'])->middleware('permission:documents.upload');
    Route::get('employees/{employee}/documents/{document}', [EmployeeDocumentController::class, 'show'])->middleware('permission:documents.view');
    Route::get('employees/{employee}/documents/{document}/download', [EmployeeDocumentController::class, 'download'])->middleware('permission:documents.download');
    Route::delete('employees/{employee}/documents/{document}', [EmployeeDocumentController::class, 'destroy'])->middleware('permission:documents.delete');

    Route::get('document-categories', [DocumentCategoryController::class, 'index'])->middleware('permission:document_categories.view');
    Route::post('document-categories', [DocumentCategoryController::class, 'store'])->middleware('permission:document_categories.create');
    Route::get('document-categories/{documentCategory}', [DocumentCategoryController::class, 'show'])->middleware('permission:document_categories.view');
    Route::patch('document-categories/{documentCategory}', [DocumentCategoryController::class, 'update'])->middleware('permission:document_categories.update');
    Route::delete('document-categories/{documentCategory}', [DocumentCategoryController::class, 'destroy'])->middleware('permission:document_categories.delete');

    // update requires policies.update as a baseline; archiving (status ->
    // archived in the request body) is additionally gated by
    // policies.archive inside the controller, since route middleware
    // can't inspect the request body value.
    Route::get('policies', [PolicyController::class, 'index'])->middleware('permission:policies.view');
    Route::post('policies', [PolicyController::class, 'store'])->middleware('permission:policies.create');
    Route::get('policies/{policy}', [PolicyController::class, 'show'])->middleware('permission:policies.view');
    Route::patch('policies/{policy}', [PolicyController::class, 'update'])->middleware('permission:policies.update');
    Route::post('policies/{policy}/versions', [PolicyController::class, 'storeVersion'])->middleware('permission:policies.update');
    Route::post('policies/{policy}/publish', [PolicyController::class, 'publish'])->middleware('permission:policies.publish');
    Route::post('policies/{policy}/assign', [PolicyController::class, 'assign'])->middleware('permission:policies.assign');
    Route::get('policies/{policy}/acknowledgements', [PolicyController::class, 'acknowledgements'])->middleware('permission:policies.view_acknowledgements');
    Route::post('policies/{policy}/acknowledge', [PolicyController::class, 'acknowledge'])->middleware('permission:policies.acknowledge');

    Route::post('employees/{employee}/link-user', [EmployeeUserLinkController::class, 'store'])->middleware('permission:employees.link_user');
    Route::delete('employees/{employee}/unlink-user', [EmployeeUserLinkController::class, 'destroy'])->middleware('permission:employees.unlink_user');

    // No specific permission — inherently self-scoped (the caller's own
    // link, resolved server-side), same as a "whoami" endpoint.
    Route::get('me/employee', [MeController::class, 'employee']);
});
