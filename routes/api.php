<?php

use App\Http\Controllers\Api\V1\AuditLogController;
use App\Http\Controllers\Api\V1\CustomFieldDefinitionController;
use App\Http\Controllers\Api\V1\DashboardController;
use App\Http\Controllers\Api\V1\DepartmentController;
use App\Http\Controllers\Api\V1\DocumentCategoryController;
use App\Http\Controllers\Api\V1\EmployeeController;
use App\Http\Controllers\Api\V1\EmployeeDocumentController;
use App\Http\Controllers\Api\V1\EmployeeHierarchyController;
use App\Http\Controllers\Api\V1\EmployeeManagerController;
use App\Http\Controllers\Api\V1\EmployeeUserLinkController;
use App\Http\Controllers\Api\V1\HrDocumentTemplateController;
use App\Http\Controllers\Api\V1\HrDocumentTemplateVersionController;
use App\Http\Controllers\Api\V1\HrGeneratedDocumentController;
use App\Http\Controllers\Api\V1\JobApplicationController;
use App\Http\Controllers\Api\V1\JobOpeningController;
use App\Http\Controllers\Api\V1\LeaveBalanceController;
use App\Http\Controllers\Api\V1\LeaveRequestController;
use App\Http\Controllers\Api\V1\LeaveTypeController;
use App\Http\Controllers\Api\V1\LifecycleProcessController;
use App\Http\Controllers\Api\V1\LifecycleTaskController;
use App\Http\Controllers\Api\V1\LifecycleTaskTemplateController;
use App\Http\Controllers\Api\V1\LocationController;
use App\Http\Controllers\Api\V1\MeController;
use App\Http\Controllers\Api\V1\PermissionController;
use App\Http\Controllers\Api\V1\PolicyController;
use App\Http\Controllers\Api\V1\PositionController;
use App\Http\Controllers\Api\V1\RoleController;
use App\Http\Controllers\Api\V1\RolePermissionController;
use App\Http\Controllers\Api\V1\TenantBrandingController;
use App\Http\Controllers\Api\V1\TenantController;
use App\Http\Controllers\Api\V1\TenantModuleController;
use App\Http\Controllers\Api\V1\UserController;
use App\Http\Controllers\Api\V1\UserRoleController;
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

    // Checkpoint 22 — singleton tenant-context endpoint, no {tenant}
    // route parameter: both actions always operate on app(Tenant::class),
    // never a request-supplied ID. tenant.view/tenant.update are
    // tenant-scoped permissions a Platform Super Admin can never hold,
    // so this middleware alone already blocks them; TenantController
    // adds an explicit is_platform_admin check too, as defense in depth.
    Route::get('tenant', [TenantController::class, 'show'])->middleware('permission:tenant.view');
    Route::patch('tenant', [TenantController::class, 'update'])->middleware('permission:tenant.update');

    // Checkpoint 47 — Module Registry & Branding Foundation. Same
    // singleton-per-tenant shape as above — no {tenant} route
    // parameter, always app(Tenant::class). {moduleKey} is a plain
    // string (not enum-bound) so an unknown/core key gets a 422 inside
    // the controller, never a route-model-binding 404.
    Route::get('tenant/modules', [TenantModuleController::class, 'index'])->middleware('permission:tenant.modules.view');
    Route::patch('tenant/modules/{moduleKey}', [TenantModuleController::class, 'update'])->middleware('permission:tenant.modules.manage');

    Route::get('tenant/branding', [TenantBrandingController::class, 'show'])->middleware('permission:tenant.branding.view');
    Route::patch('tenant/branding', [TenantBrandingController::class, 'update'])->middleware('permission:tenant.branding.manage');
    Route::post('tenant/branding/logo', [TenantBrandingController::class, 'uploadLogo'])->middleware('permission:tenant.branding.manage');
    Route::delete('tenant/branding/logo', [TenantBrandingController::class, 'removeLogo'])->middleware('permission:tenant.branding.manage');

    // Checkpoint 23 — Users & Access. User and Role do NOT use
    // BelongsToTenant (see docs/security.md) — every query in these
    // controllers manually filters by tenant_id; this is the primary
    // tenant boundary here, not defense-in-depth on top of a scope.
    Route::get('users', [UserController::class, 'index'])->middleware('permission:users.view');
    // Checkpoint 43 — the first user-creation route in this app.
    Route::post('users', [UserController::class, 'store'])->middleware('permission:users.create');
    Route::get('users/{user}', [UserController::class, 'show'])->middleware('permission:users.view');
    Route::patch('users/{user}', [UserController::class, 'update'])->middleware('permission:users.deactivate');
    Route::post('users/{user}/roles', [UserRoleController::class, 'store'])->middleware('permission:users.assign_role');
    Route::delete('users/{user}/roles/{role}', [UserRoleController::class, 'destroy'])->middleware('permission:users.assign_role');
    Route::get('roles', [RoleController::class, 'index'])->middleware('permission:roles.view');
    Route::get('permissions', [PermissionController::class, 'index'])->middleware('permission:permissions.view');

    // Checkpoint 28 — RBAC role/permission management. show()/store()/
    // update() only ever mutate custom (is_system_role: false) tenant
    // roles; a system or platform role is rejected before any field is
    // applied (RoleController::ensureNotSystemRole()/
    // ensureBelongsToCurrentTenant()). No delete route exists this
    // checkpoint — see docs/security.md.
    Route::get('roles/{role}', [RoleController::class, 'show'])->middleware('permission:roles.view');
    Route::post('roles', [RoleController::class, 'store'])->middleware('permission:roles.create');
    Route::patch('roles/{role}', [RoleController::class, 'update'])->middleware('permission:roles.update');
    // permissions.assign (existing, previously-unused key) — not
    // roles.assign_permission, which does not exist in this app's
    // permission catalog. Tenant Admin already holds it via the
    // existing "all non-platform permissions" wildcard grant; no other
    // role is granted it this checkpoint.
    Route::post('roles/{role}/permissions', [RolePermissionController::class, 'store'])->middleware('permission:permissions.assign');
    Route::delete('roles/{role}/permissions/{permission}', [RolePermissionController::class, 'destroy'])->middleware('permission:permissions.assign');

    // Checkpoint 24 — read-only. AuditLog does NOT use BelongsToTenant
    // (see docs/security.md) — every query manually filters by
    // tenant_id; this is the primary tenant boundary, not
    // defense-in-depth on top of a scope. No store/update/destroy
    // route exists anywhere — audit logs are append-only, enforced at
    // both the model layer (AuditLog::save()/delete() throw) and by
    // simply never registering a write route here.
    Route::get('audit-logs', [AuditLogController::class, 'index'])->middleware('permission:audit.view');
    Route::get('audit-logs/{auditLog}', [AuditLogController::class, 'show'])->middleware('permission:audit.view');

    Route::get('employees', [EmployeeController::class, 'index'])->middleware('permission:employees.view');
    Route::post('employees', [EmployeeController::class, 'store'])->middleware('permission:employees.create');
    Route::get('employees/{employee}', [EmployeeController::class, 'show'])->middleware('permission:employees.view');
    Route::patch('employees/{employee}', [EmployeeController::class, 'update'])->middleware('permission:employees.update');
    Route::delete('employees/{employee}', [EmployeeController::class, 'destroy'])->middleware('permission:employees.delete');

    Route::get('employees/{employee}/documents', [EmployeeDocumentController::class, 'index'])->middleware(['module:documents', 'permission:documents.view']);
    Route::post('employees/{employee}/documents', [EmployeeDocumentController::class, 'store'])->middleware(['module:documents', 'permission:documents.upload']);
    Route::get('employees/{employee}/documents/{document}', [EmployeeDocumentController::class, 'show'])->middleware(['module:documents', 'permission:documents.view']);
    Route::get('employees/{employee}/documents/{document}/download', [EmployeeDocumentController::class, 'download'])->middleware(['module:documents', 'permission:documents.download']);
    Route::delete('employees/{employee}/documents/{document}', [EmployeeDocumentController::class, 'destroy'])->middleware(['module:documents', 'permission:documents.delete']);

    Route::get('document-categories', [DocumentCategoryController::class, 'index'])->middleware(['module:documents', 'permission:document_categories.view']);
    Route::post('document-categories', [DocumentCategoryController::class, 'store'])->middleware(['module:documents', 'permission:document_categories.create']);
    Route::get('document-categories/{documentCategory}', [DocumentCategoryController::class, 'show'])->middleware(['module:documents', 'permission:document_categories.view']);
    Route::patch('document-categories/{documentCategory}', [DocumentCategoryController::class, 'update'])->middleware(['module:documents', 'permission:document_categories.update']);
    Route::delete('document-categories/{documentCategory}', [DocumentCategoryController::class, 'destroy'])->middleware(['module:documents', 'permission:document_categories.delete']);

    // Checkpoint 32 — Employee Lifecycle Foundation. Department/
    // Position/Location already use BelongsToTenant (Checkpoint 26),
    // the standard two-layer tenant pattern — same shape as
    // document-categories above, not the manual-filtering exception
    // User/Role/AuditLog need.
    Route::get('departments', [DepartmentController::class, 'index'])->middleware('permission:departments.view');
    Route::post('departments', [DepartmentController::class, 'store'])->middleware('permission:departments.create');
    Route::get('departments/{department}', [DepartmentController::class, 'show'])->middleware('permission:departments.view');
    Route::patch('departments/{department}', [DepartmentController::class, 'update'])->middleware('permission:departments.update');
    Route::delete('departments/{department}', [DepartmentController::class, 'destroy'])->middleware('permission:departments.delete');

    Route::get('positions', [PositionController::class, 'index'])->middleware('permission:positions.view');
    Route::post('positions', [PositionController::class, 'store'])->middleware('permission:positions.create');
    Route::get('positions/{position}', [PositionController::class, 'show'])->middleware('permission:positions.view');
    Route::patch('positions/{position}', [PositionController::class, 'update'])->middleware('permission:positions.update');
    Route::delete('positions/{position}', [PositionController::class, 'destroy'])->middleware('permission:positions.delete');

    Route::get('locations', [LocationController::class, 'index'])->middleware('permission:locations.view');
    Route::post('locations', [LocationController::class, 'store'])->middleware('permission:locations.create');
    Route::get('locations/{location}', [LocationController::class, 'show'])->middleware('permission:locations.view');
    Route::patch('locations/{location}', [LocationController::class, 'update'])->middleware('permission:locations.update');
    Route::delete('locations/{location}', [LocationController::class, 'destroy'])->middleware('permission:locations.delete');

    // update requires policies.update as a baseline; archiving (status ->
    // archived in the request body) is additionally gated by
    // policies.archive inside the controller, since route middleware
    // can't inspect the request body value.
    Route::get('policies', [PolicyController::class, 'index'])->middleware(['module:policies', 'permission:policies.view']);
    Route::post('policies', [PolicyController::class, 'store'])->middleware(['module:policies', 'permission:policies.create']);
    Route::get('policies/{policy}', [PolicyController::class, 'show'])->middleware(['module:policies', 'permission:policies.view']);
    Route::patch('policies/{policy}', [PolicyController::class, 'update'])->middleware(['module:policies', 'permission:policies.update']);
    Route::post('policies/{policy}/versions', [PolicyController::class, 'storeVersion'])->middleware(['module:policies', 'permission:policies.update']);
    // Read-only (Checkpoint 20) — gated by policies.view, same trust level
    // as viewing the policy itself; no new write path, no new permission.
    // Needed so the UI can show current-version content and let the user
    // pick which draft to publish without guessing a version ID.
    Route::get('policies/{policy}/versions', [PolicyController::class, 'versions'])->middleware(['module:policies', 'permission:policies.view']);
    Route::post('policies/{policy}/publish', [PolicyController::class, 'publish'])->middleware(['module:policies', 'permission:policies.publish']);
    Route::post('policies/{policy}/assign', [PolicyController::class, 'assign'])->middleware(['module:policies', 'permission:policies.assign']);
    Route::get('policies/{policy}/acknowledgements', [PolicyController::class, 'acknowledgements'])->middleware(['module:policies', 'permission:policies.view_acknowledgements']);
    Route::post('policies/{policy}/acknowledge', [PolicyController::class, 'acknowledge'])->middleware(['module:policies', 'permission:policies.acknowledge']);

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
    // linked employee's leave balances, never anyone else's. Gated by
    // module:leave (Checkpoint 47) even though it has no permission
    // middleware at all — it's still a Leave-module feature.
    Route::get('me/leave-balances', [MeController::class, 'leaveBalances'])->middleware('module:leave');

    Route::patch('employees/{employee}/manager', [EmployeeManagerController::class, 'update'])->middleware('permission:employees.update_manager');
    Route::delete('employees/{employee}/manager', [EmployeeManagerController::class, 'destroy'])->middleware('permission:employees.update_manager');
    Route::get('employees/{employee}/direct-reports', [EmployeeHierarchyController::class, 'directReports'])->middleware('permission:employees.view_team');
    Route::get('employees/{employee}/reporting-tree', [EmployeeHierarchyController::class, 'reportingTree'])->middleware('permission:employees.view_team');

    Route::get('leave-types', [LeaveTypeController::class, 'index'])->middleware(['module:leave', 'permission:leave_types.view']);
    Route::post('leave-types', [LeaveTypeController::class, 'store'])->middleware(['module:leave', 'permission:leave_types.create']);
    Route::get('leave-types/{leaveType}', [LeaveTypeController::class, 'show'])->middleware(['module:leave', 'permission:leave_types.view']);
    Route::patch('leave-types/{leaveType}', [LeaveTypeController::class, 'update'])->middleware(['module:leave', 'permission:leave_types.update']);
    Route::delete('leave-types/{leaveType}', [LeaveTypeController::class, 'destroy'])->middleware(['module:leave', 'permission:leave_types.delete']);

    // leave.view gates every leave-requests route uniformly; index()/
    // show() additionally scope by ownership vs. leave.view_all inside
    // the controller (not expressible as route middleware — see
    // docs/security.md). store()/update()/submit()/cancel() are further
    // gated by leave.request/leave.cancel plus an object-level ownership
    // check.
    Route::get('leave-requests', [LeaveRequestController::class, 'index'])->middleware(['module:leave', 'permission:leave.view']);
    Route::post('leave-requests', [LeaveRequestController::class, 'store'])->middleware(['module:leave', 'permission:leave.request']);
    Route::get('leave-requests/{leaveRequest}', [LeaveRequestController::class, 'show'])->middleware(['module:leave', 'permission:leave.view']);
    Route::patch('leave-requests/{leaveRequest}', [LeaveRequestController::class, 'update'])->middleware(['module:leave', 'permission:leave.request']);
    Route::post('leave-requests/{leaveRequest}/submit', [LeaveRequestController::class, 'submit'])->middleware(['module:leave', 'permission:leave.request']);
    Route::post('leave-requests/{leaveRequest}/approve', [LeaveRequestController::class, 'approve'])->middleware(['module:leave', 'permission:leave.approve']);
    Route::post('leave-requests/{leaveRequest}/reject', [LeaveRequestController::class, 'reject'])->middleware(['module:leave', 'permission:leave.reject']);
    Route::post('leave-requests/{leaveRequest}/cancel', [LeaveRequestController::class, 'cancel'])->middleware(['module:leave', 'permission:leave.cancel']);

    // index() requires view_all (tenant-wide list) — there's no
    // "admin's own balance" concept the way there is for leave requests;
    // self-service is exclusively through GET /me/leave-balances.
    // update() requires leave_balances.adjust in addition, only when
    // adjustment_days is present in the body — checked in the
    // controller, not expressible as route middleware.
    Route::get('leave-balances', [LeaveBalanceController::class, 'index'])->middleware(['module:leave', 'permission:leave_balances.view_all']);
    Route::post('leave-balances', [LeaveBalanceController::class, 'store'])->middleware(['module:leave', 'permission:leave_balances.create']);
    Route::get('leave-balances/{leaveBalance}', [LeaveBalanceController::class, 'show'])->middleware(['module:leave', 'permission:leave_balances.view']);
    Route::patch('leave-balances/{leaveBalance}', [LeaveBalanceController::class, 'update'])->middleware(['module:leave', 'permission:leave_balances.update']);

    // Checkpoint 33 — Onboarding & Offboarding Foundation. lifecycle.view
    // gates index()/show() uniformly; both additionally scope by
    // LifecycleVisibilityService inside the controller (not expressible
    // as route middleware — same shape as leave.view above), since Line
    // Manager and Employee hold the identical permission set here
    // (lifecycle.view + lifecycle.complete_task) despite needing
    // different visibility. See docs/security.md.
    Route::get('lifecycle-processes', [LifecycleProcessController::class, 'index'])->middleware(['module:lifecycle', 'permission:lifecycle.view']);
    Route::post('lifecycle-processes', [LifecycleProcessController::class, 'store'])->middleware(['module:lifecycle', 'permission:lifecycle.create']);
    Route::get('lifecycle-processes/{lifecycleProcess}', [LifecycleProcessController::class, 'show'])->middleware(['module:lifecycle', 'permission:lifecycle.view']);
    Route::patch('lifecycle-processes/{lifecycleProcess}', [LifecycleProcessController::class, 'update'])->middleware(['module:lifecycle', 'permission:lifecycle.update']);
    Route::delete('lifecycle-processes/{lifecycleProcess}', [LifecycleProcessController::class, 'destroy'])->middleware(['module:lifecycle', 'permission:lifecycle.delete']);

    Route::post('lifecycle-processes/{lifecycleProcess}/tasks', [LifecycleTaskController::class, 'store'])->middleware(['module:lifecycle', 'permission:lifecycle.create']);
    // Checkpoint 45 — bulk reorder, gated the same as editing the
    // process itself (lifecycle.update), not a narrower key.
    Route::post('lifecycle-processes/{lifecycleProcess}/tasks/reorder', [LifecycleTaskController::class, 'reorder'])->middleware(['module:lifecycle', 'permission:lifecycle.update']);
    Route::patch('lifecycle-tasks/{lifecycleTask}', [LifecycleTaskController::class, 'update'])->middleware(['module:lifecycle', 'permission:lifecycle.update']);
    Route::delete('lifecycle-tasks/{lifecycleTask}', [LifecycleTaskController::class, 'destroy'])->middleware(['module:lifecycle', 'permission:lifecycle.delete']);
    // complete/skip are further scoped by LifecycleVisibilityService::
    // canAccessTask() in the controller — lifecycle.complete_task alone
    // is necessary but not sufficient for Line Manager/Employee callers.
    Route::post('lifecycle-tasks/{lifecycleTask}/complete', [LifecycleTaskController::class, 'complete'])->middleware(['module:lifecycle', 'permission:lifecycle.complete_task']);
    Route::post('lifecycle-tasks/{lifecycleTask}/skip', [LifecycleTaskController::class, 'skip'])->middleware(['module:lifecycle', 'permission:lifecycle.complete_task']);

    // Checkpoint 42 — Onboarding & Offboarding Task Templates
    // Foundation. Its own permission group (lifecycle_task_templates.*),
    // not lifecycle.* — managing the template catalog is a distinct
    // admin-configuration concern from working processes/tasks.
    Route::get('lifecycle-task-templates', [LifecycleTaskTemplateController::class, 'index'])->middleware(['module:lifecycle', 'permission:lifecycle_task_templates.view']);
    Route::post('lifecycle-task-templates', [LifecycleTaskTemplateController::class, 'store'])->middleware(['module:lifecycle', 'permission:lifecycle_task_templates.create']);
    Route::get('lifecycle-task-templates/{lifecycleTaskTemplate}', [LifecycleTaskTemplateController::class, 'show'])->middleware(['module:lifecycle', 'permission:lifecycle_task_templates.view']);
    Route::patch('lifecycle-task-templates/{lifecycleTaskTemplate}', [LifecycleTaskTemplateController::class, 'update'])->middleware(['module:lifecycle', 'permission:lifecycle_task_templates.update']);
    Route::delete('lifecycle-task-templates/{lifecycleTaskTemplate}', [LifecycleTaskTemplateController::class, 'destroy'])->middleware(['module:lifecycle', 'permission:lifecycle_task_templates.delete']);

    // Checkpoint 34 — HR Documents & Letter Generation Foundation.
    // Content-only MVP (Option A, approved): rendered_content is stored
    // as plain text, no PDF/DOCX file is generated. destroy() on both
    // resources soft-deletes ("archives"), same shape as
    // document-categories above — no separate archive route needed.
    Route::get('hr-document-templates', [HrDocumentTemplateController::class, 'index'])->middleware(['module:hr_documents', 'permission:hr_document_templates.view']);
    Route::post('hr-document-templates', [HrDocumentTemplateController::class, 'store'])->middleware(['module:hr_documents', 'permission:hr_document_templates.create']);
    Route::get('hr-document-templates/{hrDocumentTemplate}', [HrDocumentTemplateController::class, 'show'])->middleware(['module:hr_documents', 'permission:hr_document_templates.view']);
    Route::patch('hr-document-templates/{hrDocumentTemplate}', [HrDocumentTemplateController::class, 'update'])->middleware(['module:hr_documents', 'permission:hr_document_templates.update']);
    Route::delete('hr-document-templates/{hrDocumentTemplate}', [HrDocumentTemplateController::class, 'destroy'])->middleware(['module:hr_documents', 'permission:hr_document_templates.delete']);
    // Checkpoint 38 — HR Document Template Library & Starter Templates.
    // Reuses .create, not a new permission — duplicating is just
    // creating a new template pre-filled from an existing one.
    Route::post('hr-document-templates/{hrDocumentTemplate}/duplicate', [HrDocumentTemplateController::class, 'duplicate'])->middleware(['module:hr_documents', 'permission:hr_document_templates.create']);

    // Checkpoint 36 — HR Document Template Versioning Foundation.
    // Version creation/editing reuses hr_document_templates.update (same
    // reasoning PolicyController::storeVersion uses policies.update, not
    // a separate permission — a version is the template's own history,
    // not a distinct resource with distinct trust). Publishing gets its
    // one new permission, hr_document_templates.publish.
    Route::get('hr-document-templates/{hrDocumentTemplate}/versions', [HrDocumentTemplateVersionController::class, 'index'])->middleware(['module:hr_documents', 'permission:hr_document_templates.view']);
    Route::post('hr-document-templates/{hrDocumentTemplate}/versions', [HrDocumentTemplateVersionController::class, 'store'])->middleware(['module:hr_documents', 'permission:hr_document_templates.update']);
    Route::get('hr-document-template-versions/{hrDocumentTemplateVersion}', [HrDocumentTemplateVersionController::class, 'show'])->middleware(['module:hr_documents', 'permission:hr_document_templates.view']);
    Route::patch('hr-document-template-versions/{hrDocumentTemplateVersion}', [HrDocumentTemplateVersionController::class, 'update'])->middleware(['module:hr_documents', 'permission:hr_document_templates.update']);
    Route::post('hr-document-template-versions/{hrDocumentTemplateVersion}/publish', [HrDocumentTemplateVersionController::class, 'publish'])->middleware(['module:hr_documents', 'permission:hr_document_templates.publish']);
    Route::delete('hr-document-template-versions/{hrDocumentTemplateVersion}', [HrDocumentTemplateVersionController::class, 'destroy'])->middleware(['module:hr_documents', 'permission:hr_document_templates.delete']);

    // store() both creates and renders in one step ("generate") — there
    // is no separate draft-without-rendering state in this checkpoint,
    // so the write action is gated by hr_generated_documents.generate,
    // not .create (.create is seeded in the permission catalog for
    // forward compatibility but not wired to a route yet, same posture
    // as the existing unused audit.export permission).
    Route::get('hr-generated-documents', [HrGeneratedDocumentController::class, 'index'])->middleware(['module:hr_documents', 'permission:hr_generated_documents.view']);
    Route::post('hr-generated-documents', [HrGeneratedDocumentController::class, 'store'])->middleware(['module:hr_documents', 'permission:hr_generated_documents.generate']);
    Route::get('hr-generated-documents/{hrGeneratedDocument}', [HrGeneratedDocumentController::class, 'show'])->middleware(['module:hr_documents', 'permission:hr_generated_documents.view']);
    // Checkpoint 35 — Option B (approved): PDF generated on demand from
    // rendered_content, never stored. Same permission as the JSON show
    // route above — downloading a PDF isn't a new capability.
    Route::get('hr-generated-documents/{hrGeneratedDocument}/download-pdf', [HrGeneratedDocumentController::class, 'downloadPdf'])->middleware(['module:hr_documents', 'permission:hr_generated_documents.view']);
    Route::patch('hr-generated-documents/{hrGeneratedDocument}', [HrGeneratedDocumentController::class, 'update'])->middleware(['module:hr_documents', 'permission:hr_generated_documents.update']);
    Route::delete('hr-generated-documents/{hrGeneratedDocument}', [HrGeneratedDocumentController::class, 'destroy'])->middleware(['module:hr_documents', 'permission:hr_generated_documents.delete']);

    // Checkpoint 37 — HR Document Approval Workflow Foundation. submit()
    // handles both draft->pending_approval and rejected->pending_approval
    // (resubmit) through the same route/permission.
    Route::post('hr-generated-documents/{hrGeneratedDocument}/submit', [HrGeneratedDocumentController::class, 'submit'])->middleware(['module:hr_documents', 'permission:hr_generated_documents.submit']);
    Route::post('hr-generated-documents/{hrGeneratedDocument}/approve', [HrGeneratedDocumentController::class, 'approve'])->middleware(['module:hr_documents', 'permission:hr_generated_documents.approve']);
    Route::post('hr-generated-documents/{hrGeneratedDocument}/reject', [HrGeneratedDocumentController::class, 'reject'])->middleware(['module:hr_documents', 'permission:hr_generated_documents.reject']);

    // Checkpoint 39 — Recruitment & Applicant Tracking Foundation.
    // Internal HR/Admin only — no public candidate-facing routes exist
    // (no separate guard needed beyond the standard auth/tenant.matches/
    // permission stack every route here already uses).
    Route::get('job-openings', [JobOpeningController::class, 'index'])->middleware(['module:recruitment', 'permission:job_openings.view']);
    Route::post('job-openings', [JobOpeningController::class, 'store'])->middleware(['module:recruitment', 'permission:job_openings.create']);
    Route::get('job-openings/{jobOpening}', [JobOpeningController::class, 'show'])->middleware(['module:recruitment', 'permission:job_openings.view']);
    Route::patch('job-openings/{jobOpening}', [JobOpeningController::class, 'update'])->middleware(['module:recruitment', 'permission:job_openings.update']);
    Route::delete('job-openings/{jobOpening}', [JobOpeningController::class, 'destroy'])->middleware(['module:recruitment', 'permission:job_openings.delete']);

    Route::get('job-applications', [JobApplicationController::class, 'index'])->middleware(['module:recruitment', 'permission:job_applications.view']);
    Route::post('job-applications', [JobApplicationController::class, 'store'])->middleware(['module:recruitment', 'permission:job_applications.create']);
    Route::get('job-applications/{jobApplication}', [JobApplicationController::class, 'show'])->middleware(['module:recruitment', 'permission:job_applications.view']);
    Route::patch('job-applications/{jobApplication}', [JobApplicationController::class, 'update'])->middleware(['module:recruitment', 'permission:job_applications.update']);
    Route::delete('job-applications/{jobApplication}', [JobApplicationController::class, 'destroy'])->middleware(['module:recruitment', 'permission:job_applications.delete']);
    Route::post('job-applications/{jobApplication}/notes', [JobApplicationController::class, 'storeNote'])->middleware(['module:recruitment', 'permission:job_applications.add_note']);
    Route::patch('job-applications/{jobApplication}/stage', [JobApplicationController::class, 'updateStage'])->middleware(['module:recruitment', 'permission:job_applications.update_stage']);
    Route::patch('job-applications/{jobApplication}/ready-for-conversion', [JobApplicationController::class, 'markReadyForConversion'])->middleware(['module:recruitment', 'permission:job_applications.mark_ready_for_conversion']);
    // Checkpoint 40 — Candidate-to-Employee Conversion Foundation. Gated
    // solely by job_applications.convert_to_employee, not also
    // employees.create — your explicit approved choice.
    Route::post('job-applications/{jobApplication}/convert-to-employee', [JobApplicationController::class, 'convertToEmployee'])->middleware(['module:recruitment', 'permission:job_applications.convert_to_employee']);
    // Checkpoint 41 — Recruitment-to-Onboarding Handoff Foundation.
    // Gated by lifecycle.create (reused, not a new recruitment-specific
    // permission) — your explicit approved choice: starting onboarding
    // is a lifecycle action, not just a recruitment one.
    // Checkpoint 47 — gated by BOTH modules: this is fundamentally a
    // recruitment action (its own audit trail lives on the
    // RecruitmentApplication) that also creates a lifecycle process, so
    // disabling either module blocks it — matches your explicit
    // requirement that this handoff must disappear when Lifecycle is
    // disabled, not just when Recruitment is.
    Route::post('job-applications/{jobApplication}/start-onboarding', [JobApplicationController::class, 'startOnboarding'])->middleware(['module:recruitment', 'module:lifecycle', 'permission:lifecycle.create']);

    // Checkpoint 48 — Custom Fields Foundation. {entityType} is a plain
    // string, resolved/validated inside the controller (422 on unknown,
    // never a 404) — same posture as {moduleKey} above. Checkpoint 51 —
    // no static module:{key} gate here anymore: which module (if any)
    // must be enabled is entity-type-aware, resolved at runtime from
    // CustomFieldEntity::requiredModule() inside the controller itself,
    // since a single route can no longer assume every entity it serves
    // belongs to the same module (Employee belongs to none — a core,
    // never-toggleable module). custom_fields.manage controls
    // definitions only — reading/writing an entity's own values stays
    // gated by that entity's own permission (job_applications.view/
    // .update/employees.view/.update above), never a second value
    // permission axis.
    Route::get('custom-fields/{entityType}', [CustomFieldDefinitionController::class, 'index'])->middleware(['permission:custom_fields.view']);
    Route::post('custom-fields/{entityType}', [CustomFieldDefinitionController::class, 'store'])->middleware(['permission:custom_fields.manage']);
    Route::patch('custom-fields/{customFieldDefinition}', [CustomFieldDefinitionController::class, 'update'])->middleware(['permission:custom_fields.manage']);
});
