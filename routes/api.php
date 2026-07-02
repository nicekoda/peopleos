<?php

use App\Http\Controllers\Api\V1\EmployeeController;
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
*/

Route::middleware('auth')->prefix('api/v1')->group(function () {
    Route::get('employees', [EmployeeController::class, 'index'])->middleware('permission:employees.view');
    Route::post('employees', [EmployeeController::class, 'store'])->middleware('permission:employees.create');
    Route::get('employees/{employee}', [EmployeeController::class, 'show'])->middleware('permission:employees.view');
    Route::patch('employees/{employee}', [EmployeeController::class, 'update'])->middleware('permission:employees.update');
    Route::delete('employees/{employee}', [EmployeeController::class, 'destroy'])->middleware('permission:employees.delete');
});
