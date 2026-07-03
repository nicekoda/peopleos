<?php

use App\Http\Controllers\DashboardController;
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

    Route::get('employees', fn () => Inertia::render('Employees/Index'))
        ->middleware('permission:employees.view')->name('employees.index');
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
