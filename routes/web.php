<?php

use App\Http\Controllers\DashboardController;
use App\Http\Controllers\EmployeeDocumentUiController;
use App\Http\Controllers\EmployeeUiController;
use App\Http\Controllers\LeaveUiController;
use App\Http\Controllers\PolicyUiController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', function () {
    return view('welcome');
});

/*
|--------------------------------------------------------------------------
| Frontend (Inertia) routes — Checkpoint 16
|--------------------------------------------------------------------------
|
| Same auth/tenant.matches middleware pattern as every /api/v1 route (see
| routes/api.php) — this is a UI shell over the existing API, not a
| separate security model. The 5 module placeholders are additionally
| gated by the same permission each module's real API endpoints already
| require: hiding a sidebar link is not security, so the *page route*
| itself is backend-permission-gated too, not just invisible in the nav.
| See docs/security.md.
|
*/
Route::middleware(['auth', 'tenant.matches'])->group(function () {
    Route::get('dashboard', [DashboardController::class, 'index'])->name('dashboard');

    // Employee Records UI (Checkpoint 17) — thin page routes; the actual
    // employee data is fetched client-side from /api/v1/employees, never
    // passed through as an Inertia prop. 'employees/create' must be
    // registered before 'employees/{employee}' so Laravel doesn't treat
    // "create" as an {employee} route parameter.
    Route::get('employees', [EmployeeUiController::class, 'index'])
        ->middleware('permission:employees.view')->name('employees.index');
    Route::get('employees/create', [EmployeeUiController::class, 'create'])
        ->middleware('permission:employees.create')->name('employees.create');
    Route::get('employees/{employee}', [EmployeeUiController::class, 'show'])
        ->middleware('permission:employees.view')->name('employees.show');
    Route::get('employees/{employee}/edit', [EmployeeUiController::class, 'edit'])
        ->middleware('permission:employees.update')->name('employees.edit');

    // Document Repository UI (Checkpoint 19) — employee-scoped, same
    // thin-page-route pattern: document data is fetched client-side from
    // the existing /api/v1/employees/{employee}/documents endpoints
    // (Checkpoint 8), never passed through as an Inertia prop.
    // 'documents/upload' must be registered before 'documents/{document}'
    // so Laravel doesn't treat "upload" as a {document} route parameter.
    Route::get('employees/{employee}/documents', [EmployeeDocumentUiController::class, 'index'])
        ->middleware('permission:documents.view')->name('employees.documents.index');
    Route::get('employees/{employee}/documents/upload', [EmployeeDocumentUiController::class, 'create'])
        ->middleware('permission:documents.upload')->name('employees.documents.create');
    Route::get('employees/{employee}/documents/{document}', [EmployeeDocumentUiController::class, 'show'])
        ->middleware('permission:documents.view')->name('employees.documents.show');

    // Leave Management UI (Checkpoint 18) — same thin-page-route pattern
    // as Employee Records (Checkpoint 17): the actual leave request/
    // type/balance data is fetched client-side from the existing
    // /api/v1 endpoints, never passed through as an Inertia prop.
    // 'leave/create' must be registered before 'leave/{leaveRequest}' so
    // Laravel doesn't treat "create" as a route parameter.
    Route::get('leave', [LeaveUiController::class, 'index'])
        ->middleware('permission:leave.view')->name('leave.index');
    Route::get('leave/create', [LeaveUiController::class, 'create'])
        ->middleware('permission:leave.request')->name('leave.create');
    Route::get('leave/{leaveRequest}', [LeaveUiController::class, 'show'])
        ->middleware('permission:leave.view')->name('leave.show');
    Route::get('documents', fn () => Inertia::render('Documents/Index'))
        ->middleware('permission:documents.view')->name('documents.index');

    // Policy Management UI (Checkpoint 20) — same thin-page-route pattern
    // as every other module: policy/version/acknowledgement data is
    // fetched client-side from the existing /api/v1/policies endpoints,
    // never passed through as an Inertia prop. 'policies/create' must be
    // registered before 'policies/{policy}' so Laravel doesn't treat
    // "create" as a route parameter.
    Route::get('policies', [PolicyUiController::class, 'index'])
        ->middleware('permission:policies.view')->name('policies.index');
    Route::get('policies/create', [PolicyUiController::class, 'create'])
        ->middleware('permission:policies.create')->name('policies.create');
    Route::get('policies/{policy}', [PolicyUiController::class, 'show'])
        ->middleware('permission:policies.view')->name('policies.show');
    Route::get('policies/{policy}/edit', [PolicyUiController::class, 'edit'])
        ->middleware('permission:policies.update')->name('policies.edit');
    // Version creation shares policies.update, same as the API endpoint
    // it drives (POST /api/v1/policies/{policy}/versions).
    Route::get('policies/{policy}/versions/create', [PolicyUiController::class, 'createVersion'])
        ->middleware('permission:policies.update')->name('policies.versions.create');
    Route::get('policies/{policy}/assign', [PolicyUiController::class, 'assign'])
        ->middleware('permission:policies.assign')->name('policies.assign');
    Route::get('policies/{policy}/acknowledgements', [PolicyUiController::class, 'acknowledgements'])
        ->middleware('permission:policies.view_acknowledgements')->name('policies.acknowledgements');
    // No dedicated "settings.view" permission exists yet — employees.update
    // is used as a reasonable stand-in signal of "this user has some
    // administrative capability." Revisit if/when Settings grows real
    // content with its own permission needs.
    Route::get('settings', fn () => Inertia::render('Settings/Index'))
        ->middleware('permission:employees.update')->name('settings.index');
});

require __DIR__.'/auth.php';
require __DIR__.'/api.php';
