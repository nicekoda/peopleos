<?php

use App\Http\Controllers\Api\V1\DashboardController;
use App\Http\Controllers\Api\V1\DocumentCategoryController;
use App\Http\Controllers\Api\V1\EmployeeController;
use App\Http\Controllers\Api\V1\EmployeeDocumentController;
use App\Http\Controllers\Api\V1\EmployeeHierarchyController;
use App\Http\Controllers\Api\V1\EmployeeManagerController;
use App\Http\Controllers\Api\V1\EmployeeUserLinkController;
use App\Http\Controllers\Api\V1\LeaveBalanceController;
use App\Http\Controllers\Api\V1\LeaveRequestController;
use App\Http\Controllers\Api\V1\LeaveTypeController;
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
    // Checkpoint 21 — dashboard.view is a tenant-scoped permission, so a
    // Platform Super Admin can never hold it (permission-assignment
    // scope guards, see HasPermissions) — this middleware alone already
    // blocks them from this endpoint; DashboardController::summary()
    // adds an explicit is_platform_admin check too, as defense in depth.
    Route::get('dashboard', [DashboardController::class, 'summary'])->middleware('permission:dashboard.view');

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
    // Read-only (Checkpoint 20) — gated by policies.view, same trust level
    // as viewing the policy itself; no new write path, no new permission.
    // Needed so the UI can show current-version content and let the user
    // pick which draft to publish without guessing a version ID.
    Route::get('policies/{policy}/versions', [PolicyController::class, 'versions'])->middleware('permission:policies.view');
    Route::post('policies/{policy}/publish', [PolicyController::class, 'publish'])->middleware('permission:policies.publish');
    Route::post('policies/{policy}/assign', [PolicyController::class, 'assign'])->middleware('permission:policies.assign');
    Route::get('policies/{policy}/acknowledgements', [PolicyController::class, 'acknowledgements'])->middleware('permission:policies.view_acknowledgements');
    Route::post('policies/{policy}/acknowledge', [PolicyController::class, 'acknowledge'])->middleware('permission:policies.acknowledge');

    Route::post('employees/{employee}/link-user', [EmployeeUserLinkController::class, 'store'])->middleware('permission:employees.link_user');
    Route::delete('employees/{employee}/unlink-user', [EmployeeUserLinkController::class, 'destroy'])->middleware('permission:employees.unlink_user');

    // No specific permission — inherently self-scoped (the caller's own
    // link, resolved server-side), same as a "whoami" endpoint.
    Route::get('me/employee', [MeController::class, 'employee']);
    // Also no specific permission — scoped only to the caller's own
    // linked employee's direct reports, never anyone else's. See
    // docs/security.md.
    Route::get('me/direct-reports', [MeController::class, 'directReports']);
    // Also no specific permission — scoped only to the caller's own
    // linked employee's leave balances, never anyone else's.
    Route::get('me/leave-balances', [MeController::class, 'leaveBalances']);

    Route::patch('employees/{employee}/manager', [EmployeeManagerController::class, 'update'])->middleware('permission:employees.update_manager');
    Route::delete('employees/{employee}/manager', [EmployeeManagerController::class, 'destroy'])->middleware('permission:employees.update_manager');
    Route::get('employees/{employee}/direct-reports', [EmployeeHierarchyController::class, 'directReports'])->middleware('permission:employees.view_team');
    Route::get('employees/{employee}/reporting-tree', [EmployeeHierarchyController::class, 'reportingTree'])->middleware('permission:employees.view_team');

    Route::get('leave-types', [LeaveTypeController::class, 'index'])->middleware('permission:leave_types.view');
    Route::post('leave-types', [LeaveTypeController::class, 'store'])->middleware('permission:leave_types.create');
    Route::get('leave-types/{leaveType}', [LeaveTypeController::class, 'show'])->middleware('permission:leave_types.view');
    Route::patch('leave-types/{leaveType}', [LeaveTypeController::class, 'update'])->middleware('permission:leave_types.update');
    Route::delete('leave-types/{leaveType}', [LeaveTypeController::class, 'destroy'])->middleware('permission:leave_types.delete');

    // leave.view gates every leave-requests route uniformly; index()/
    // show() additionally scope by ownership vs. leave.view_all inside
    // the controller (not expressible as route middleware — see
    // docs/security.md). store()/update()/submit()/cancel() are further
    // gated by leave.request/leave.cancel plus an object-level ownership
    // check.
    Route::get('leave-requests', [LeaveRequestController::class, 'index'])->middleware('permission:leave.view');
    Route::post('leave-requests', [LeaveRequestController::class, 'store'])->middleware('permission:leave.request');
    Route::get('leave-requests/{leaveRequest}', [LeaveRequestController::class, 'show'])->middleware('permission:leave.view');
    Route::patch('leave-requests/{leaveRequest}', [LeaveRequestController::class, 'update'])->middleware('permission:leave.request');
    Route::post('leave-requests/{leaveRequest}/submit', [LeaveRequestController::class, 'submit'])->middleware('permission:leave.request');
    Route::post('leave-requests/{leaveRequest}/approve', [LeaveRequestController::class, 'approve'])->middleware('permission:leave.approve');
    Route::post('leave-requests/{leaveRequest}/reject', [LeaveRequestController::class, 'reject'])->middleware('permission:leave.reject');
    Route::post('leave-requests/{leaveRequest}/cancel', [LeaveRequestController::class, 'cancel'])->middleware('permission:leave.cancel');

    // index() requires view_all (tenant-wide list) — there's no
    // "admin's own balance" concept the way there is for leave requests;
    // self-service is exclusively through GET /me/leave-balances.
    // update() requires leave_balances.adjust in addition, only when
    // adjustment_days is present in the body — checked in the
    // controller, not expressible as route middleware.
    Route::get('leave-balances', [LeaveBalanceController::class, 'index'])->middleware('permission:leave_balances.view_all');
    Route::post('leave-balances', [LeaveBalanceController::class, 'store'])->middleware('permission:leave_balances.create');
    Route::get('leave-balances/{leaveBalance}', [LeaveBalanceController::class, 'show'])->middleware('permission:leave_balances.view');
    Route::patch('leave-balances/{leaveBalance}', [LeaveBalanceController::class, 'update'])->middleware('permission:leave_balances.update');
});
