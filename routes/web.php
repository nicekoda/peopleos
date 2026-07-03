<?php

use App\Http\Controllers\DashboardController;
use App\Http\Controllers\EmployeeUiController;
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

    Route::get('leave', fn () => Inertia::render('Leave/Index'))
        ->middleware('permission:leave.view')->name('leave.index');
    Route::get('documents', fn () => Inertia::render('Documents/Index'))
        ->middleware('permission:documents.view')->name('documents.index');
    Route::get('policies', fn () => Inertia::render('Policies/Index'))
        ->middleware('permission:policies.view')->name('policies.index');
    // No dedicated "settings.view" permission exists yet — employees.update
    // is used as a reasonable stand-in signal of "this user has some
    // administrative capability." Revisit if/when Settings grows real
    // content with its own permission needs.
    Route::get('settings', fn () => Inertia::render('Settings/Index'))
        ->middleware('permission:employees.update')->name('settings.index');
});

require __DIR__.'/auth.php';
require __DIR__.'/api.php';
