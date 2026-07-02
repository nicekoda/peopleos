<?php

use App\Http\Controllers\Api\V1\EmployeeController;
use App\Http\Controllers\Api\V1\EmployeeDocumentController;
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
});
