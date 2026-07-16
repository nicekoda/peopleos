<?php

use App\Http\Controllers\AuditLogUiController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DepartmentUiController;
use App\Http\Controllers\DocumentCategoryUiController;
use App\Http\Controllers\EmployeeDocumentUiController;
use App\Http\Controllers\EmployeeUiController;
use App\Http\Controllers\HrDocumentTemplateUiController;
use App\Http\Controllers\HrGeneratedDocumentUiController;
use App\Http\Controllers\LeaveTypeUiController;
use App\Http\Controllers\LeaveUiController;
use App\Http\Controllers\LifecycleTaskTemplateUiController;
use App\Http\Controllers\LifecycleUiController;
use App\Http\Controllers\LocationUiController;
use App\Http\Controllers\PolicyUiController;
use App\Http\Controllers\PositionUiController;
use App\Http\Controllers\RecruitmentUiController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\UsersAccessUiController;
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
        ->middleware(['module:documents', 'permission:documents.view'])->name('employees.documents.index');
    Route::get('employees/{employee}/documents/upload', [EmployeeDocumentUiController::class, 'create'])
        ->middleware(['module:documents', 'permission:documents.upload'])->name('employees.documents.create');
    Route::get('employees/{employee}/documents/{document}', [EmployeeDocumentUiController::class, 'show'])
        ->middleware(['module:documents', 'permission:documents.view'])->name('employees.documents.show');

    // Leave Management UI (Checkpoint 18) — same thin-page-route pattern
    // as Employee Records (Checkpoint 17): the actual leave request/
    // type/balance data is fetched client-side from the existing
    // /api/v1 endpoints, never passed through as an Inertia prop.
    // 'leave/create' must be registered before 'leave/{leaveRequest}' so
    // Laravel doesn't treat "create" as a route parameter.
    Route::get('leave', [LeaveUiController::class, 'index'])
        ->middleware(['module:leave', 'permission:leave.view'])->name('leave.index');
    Route::get('leave/create', [LeaveUiController::class, 'create'])
        ->middleware(['module:leave', 'permission:leave.request'])->name('leave.create');
    Route::get('leave/{leaveRequest}', [LeaveUiController::class, 'show'])
        ->middleware(['module:leave', 'permission:leave.view'])->name('leave.show');

    // Onboarding & Offboarding Foundation UI (Checkpoint 33) — generic
    // /lifecycle routes internally; page components label each process
    // Onboarding/Offboarding from its own `type` field once loaded. Same
    // thin-page-route pattern as every other module: process/task data
    // is fetched client-side from /api/v1/lifecycle-processes and
    // /api/v1/lifecycle-tasks, never passed as an Inertia prop beyond
    // IDs. 'create' and 'tasks/create' must be registered before
    // '{lifecycleProcess}'/'{lifecycleProcess}/edit' so Laravel doesn't
    // treat them as route parameters.
    Route::get('lifecycle', [LifecycleUiController::class, 'index'])
        ->middleware(['module:lifecycle', 'permission:lifecycle.view'])->name('lifecycle.index');
    Route::get('lifecycle/create', [LifecycleUiController::class, 'create'])
        ->middleware(['module:lifecycle', 'permission:lifecycle.create'])->name('lifecycle.create');
    Route::get('lifecycle/{lifecycleProcess}', [LifecycleUiController::class, 'show'])
        ->middleware(['module:lifecycle', 'permission:lifecycle.view'])->name('lifecycle.show');
    Route::get('lifecycle/{lifecycleProcess}/edit', [LifecycleUiController::class, 'edit'])
        ->middleware(['module:lifecycle', 'permission:lifecycle.update'])->name('lifecycle.edit');
    Route::get('lifecycle/{lifecycleProcess}/tasks/create', [LifecycleUiController::class, 'taskCreate'])
        ->middleware(['module:lifecycle', 'permission:lifecycle.create'])->name('lifecycle.tasks.create');
    Route::get('lifecycle/tasks/{lifecycleTask}/edit', [LifecycleUiController::class, 'taskEdit'])
        ->middleware(['module:lifecycle', 'permission:lifecycle.update'])->name('lifecycle.tasks.edit');

    Route::get('documents', fn () => Inertia::render('Documents/Index'))
        ->middleware(['module:documents', 'permission:documents.view'])->name('documents.index');

    // Recruitment & Applicant Tracking Foundation UI (Checkpoint 39) —
    // same thin-page-route pattern as every other module: job/application
    // data is fetched client-side from /api/v1/job-openings and
    // /api/v1/job-applications, never passed through as an Inertia prop
    // beyond IDs. No blanket permission on the /recruitment landing page
    // itself (same "access, not data" two-layer design as Settings) —
    // each card there is separately gated by its own permission; 'create'
    // routes must be registered before '{id}'/'{id}/edit' so Laravel
    // doesn't treat them as route parameters.
    Route::get('recruitment', [RecruitmentUiController::class, 'index'])->middleware('module:recruitment')->name('recruitment.index');
    Route::get('recruitment/jobs', [RecruitmentUiController::class, 'jobsIndex'])
        ->middleware(['module:recruitment', 'permission:job_openings.view'])->name('recruitment.jobs.index');
    Route::get('recruitment/jobs/create', [RecruitmentUiController::class, 'jobsCreate'])
        ->middleware(['module:recruitment', 'permission:job_openings.create'])->name('recruitment.jobs.create');
    Route::get('recruitment/jobs/{jobOpening}/edit', [RecruitmentUiController::class, 'jobsEdit'])
        ->middleware(['module:recruitment', 'permission:job_openings.update'])->name('recruitment.jobs.edit');
    Route::get('recruitment/applications', [RecruitmentUiController::class, 'applicationsIndex'])
        ->middleware(['module:recruitment', 'permission:job_applications.view'])->name('recruitment.applications.index');
    Route::get('recruitment/applications/create', [RecruitmentUiController::class, 'applicationsCreate'])
        ->middleware(['module:recruitment', 'permission:job_applications.create'])->name('recruitment.applications.create');
    Route::get('recruitment/applications/{jobApplication}', [RecruitmentUiController::class, 'applicationsShow'])
        ->middleware(['module:recruitment', 'permission:job_applications.view'])->name('recruitment.applications.show');

    // HR Documents & Letter Generation Foundation UI (Checkpoint 34) —
    // same thin-page-route pattern as every other module: document data
    // is fetched client-side from the existing /api/v1/hr-generated-documents
    // endpoints, never passed through as an Inertia prop beyond the ID.
    // 'create' must be registered before '{hrGeneratedDocument}' so
    // Laravel doesn't treat "create" as a route parameter.
    Route::get('hr-documents', [HrGeneratedDocumentUiController::class, 'index'])
        ->middleware(['module:hr_documents', 'permission:hr_generated_documents.view'])->name('hr-documents.index');
    Route::get('hr-documents/create', [HrGeneratedDocumentUiController::class, 'create'])
        ->middleware(['module:hr_documents', 'permission:hr_generated_documents.generate'])->name('hr-documents.create');
    Route::get('hr-documents/{hrGeneratedDocument}', [HrGeneratedDocumentUiController::class, 'show'])
        ->middleware(['module:hr_documents', 'permission:hr_generated_documents.view'])->name('hr-documents.show');

    // Policy Management UI (Checkpoint 20) — same thin-page-route pattern
    // as every other module: policy/version/acknowledgement data is
    // fetched client-side from the existing /api/v1/policies endpoints,
    // never passed through as an Inertia prop. 'policies/create' must be
    // registered before 'policies/{policy}' so Laravel doesn't treat
    // "create" as a route parameter.
    Route::get('policies', [PolicyUiController::class, 'index'])
        ->middleware(['module:policies', 'permission:policies.view'])->name('policies.index');
    Route::get('policies/create', [PolicyUiController::class, 'create'])
        ->middleware(['module:policies', 'permission:policies.create'])->name('policies.create');
    Route::get('policies/{policy}', [PolicyUiController::class, 'show'])
        ->middleware(['module:policies', 'permission:policies.view'])->name('policies.show');
    Route::get('policies/{policy}/edit', [PolicyUiController::class, 'edit'])
        ->middleware(['module:policies', 'permission:policies.update'])->name('policies.edit');
    // Version creation shares policies.update, same as the API endpoint
    // it drives (POST /api/v1/policies/{policy}/versions).
    Route::get('policies/{policy}/versions/create', [PolicyUiController::class, 'createVersion'])
        ->middleware(['module:policies', 'permission:policies.update'])->name('policies.versions.create');
    Route::get('policies/{policy}/assign', [PolicyUiController::class, 'assign'])
        ->middleware(['module:policies', 'permission:policies.assign'])->name('policies.assign');
    Route::get('policies/{policy}/acknowledgements', [PolicyUiController::class, 'acknowledgements'])
        ->middleware(['module:policies', 'permission:policies.view_acknowledgements'])->name('policies.acknowledgements');
    // Settings Foundation (Checkpoint 22) — same "access, not data"
    // two-layer design as the Dashboard (Checkpoint 21):
    // tenant.settings.view grants reaching /settings; each section
    // below is separately gated by its own module permission, checked
    // again wherever that section's real data lives. No blanket
    // middleware on the landing page itself — see SettingsController.
    Route::get('settings', [SettingsController::class, 'index'])->name('settings.index');
    Route::get('settings/company', [SettingsController::class, 'company'])
        ->middleware('permission:tenant.view')->name('settings.company');
    // Checkpoint 47 — Module Registry & Branding Foundation.
    Route::get('settings/modules', [SettingsController::class, 'modules'])
        ->middleware('permission:tenant.modules.view')->name('settings.modules');
    Route::get('settings/branding', [SettingsController::class, 'branding'])
        ->middleware('permission:tenant.branding.view')->name('settings.branding');

    // Safe "coming later" placeholders — same pattern originally used
    // for Documents/Policies in Checkpoint 16, each gated by the
    // existing permission closest to that section's real future data,
    // not a new permission invented for a page with zero content yet.
    Route::get('settings/access', fn () => Inertia::render('Settings/Access'))
        ->middleware('permission:users.view')->name('settings.access');

    // Users & Access Management UI (Checkpoint 23) — thin page routes;
    // user/role data is fetched client-side from the new
    // /api/v1/users|roles|permissions endpoints, never passed through
    // as an Inertia prop (except the tenant-checked userId on the
    // detail page). 'settings/access/users' must be registered before
    // 'settings/access/users/{user}' so Laravel doesn't collide the two.
    Route::get('settings/access/users', [UsersAccessUiController::class, 'users'])
        ->middleware('permission:users.view')->name('settings.access.users');
    // Checkpoint 43 — 'create' must be registered before '{user}' so
    // Laravel doesn't collide the two, same ordering rule already
    // followed for roles/create below.
    Route::get('settings/access/users/create', [UsersAccessUiController::class, 'userCreate'])
        ->middleware('permission:users.create')->name('settings.access.users.create');
    Route::get('settings/access/users/{user}', [UsersAccessUiController::class, 'show'])
        ->middleware('permission:users.view')->name('settings.access.users.show');
    Route::get('settings/access/roles', [UsersAccessUiController::class, 'roles'])
        ->middleware('permission:roles.view')->name('settings.access.roles');
    // RBAC Role/Permission Management UI (Checkpoint 28) — 'create' must
    // be registered before '{role}' so Laravel doesn't collide the two.
    Route::get('settings/access/roles/create', [UsersAccessUiController::class, 'roleCreate'])
        ->middleware('permission:roles.create')->name('settings.access.roles.create');
    Route::get('settings/access/roles/{role}', [UsersAccessUiController::class, 'roleShow'])
        ->middleware('permission:roles.view')->name('settings.access.roles.show');
    Route::get('settings/access/roles/{role}/edit', [UsersAccessUiController::class, 'roleEdit'])
        ->middleware('permission:roles.update')->name('settings.access.roles.edit');
    // Document Categories Admin UI (Checkpoint 25) — thin page routes;
    // category data is fetched client-side from the existing
    // /api/v1/document-categories endpoints (Checkpoint 9), never
    // passed through as an Inertia prop. 'create' must be registered
    // before '{documentCategory}/edit' so Laravel doesn't collide the two.
    Route::get('settings/document-categories', [DocumentCategoryUiController::class, 'index'])
        ->middleware(['module:documents', 'permission:document_categories.view'])->name('settings.document-categories');
    Route::get('settings/document-categories/create', [DocumentCategoryUiController::class, 'create'])
        ->middleware(['module:documents', 'permission:document_categories.create'])->name('settings.document-categories.create');
    Route::get('settings/document-categories/{documentCategory}/edit', [DocumentCategoryUiController::class, 'edit'])
        ->middleware(['module:documents', 'permission:document_categories.update'])->name('settings.document-categories.edit');

    // HR Document Templates Admin UI (Checkpoint 34) — same thin-page-
    // route pattern as Document Categories above. 'create' must be
    // registered before '{hrDocumentTemplate}/edit' so Laravel doesn't
    // collide the two.
    Route::get('settings/hr-document-templates', [HrDocumentTemplateUiController::class, 'index'])
        ->middleware(['module:hr_documents', 'permission:hr_document_templates.view'])->name('settings.hr-document-templates');
    Route::get('settings/hr-document-templates/create', [HrDocumentTemplateUiController::class, 'create'])
        ->middleware(['module:hr_documents', 'permission:hr_document_templates.create'])->name('settings.hr-document-templates.create');
    Route::get('settings/hr-document-templates/{hrDocumentTemplate}/edit', [HrDocumentTemplateUiController::class, 'edit'])
        ->middleware(['module:hr_documents', 'permission:hr_document_templates.update'])->name('settings.hr-document-templates.edit');
    // Checkpoint 36 — HR Document Template Versioning Foundation.
    Route::get('settings/hr-document-templates/{hrDocumentTemplate}/versions/create', [HrDocumentTemplateUiController::class, 'versionCreate'])
        ->middleware(['module:hr_documents', 'permission:hr_document_templates.update'])->name('settings.hr-document-templates.versions.create');
    Route::get('settings/hr-document-template-versions/{hrDocumentTemplateVersion}/edit', [HrDocumentTemplateUiController::class, 'versionEdit'])
        ->middleware(['module:hr_documents', 'permission:hr_document_templates.update'])->name('settings.hr-document-template-versions.edit');

    // Employee Lifecycle Foundation (Checkpoint 32) — Departments/
    // Positions/Locations Admin UI, same thin-page-route pattern. 'create'
    // must be registered before '{department}/edit' etc. so Laravel
    // doesn't collide the two.
    Route::get('settings/departments', [DepartmentUiController::class, 'index'])
        ->middleware('permission:departments.view')->name('settings.departments');
    Route::get('settings/departments/create', [DepartmentUiController::class, 'create'])
        ->middleware('permission:departments.create')->name('settings.departments.create');
    Route::get('settings/departments/{department}/edit', [DepartmentUiController::class, 'edit'])
        ->middleware('permission:departments.update')->name('settings.departments.edit');

    Route::get('settings/positions', [PositionUiController::class, 'index'])
        ->middleware('permission:positions.view')->name('settings.positions');
    Route::get('settings/positions/create', [PositionUiController::class, 'create'])
        ->middleware('permission:positions.create')->name('settings.positions.create');
    Route::get('settings/positions/{position}/edit', [PositionUiController::class, 'edit'])
        ->middleware('permission:positions.update')->name('settings.positions.edit');

    // Checkpoint 42 — Onboarding & Offboarding Task Templates Foundation.
    // Same thin-page-route pattern as Departments/Positions/Locations
    // above.
    Route::get('settings/lifecycle-task-templates', [LifecycleTaskTemplateUiController::class, 'index'])
        ->middleware(['module:lifecycle', 'permission:lifecycle_task_templates.view'])->name('settings.lifecycle-task-templates');
    Route::get('settings/lifecycle-task-templates/create', [LifecycleTaskTemplateUiController::class, 'create'])
        ->middleware(['module:lifecycle', 'permission:lifecycle_task_templates.create'])->name('settings.lifecycle-task-templates.create');
    Route::get('settings/lifecycle-task-templates/{lifecycleTaskTemplate}/edit', [LifecycleTaskTemplateUiController::class, 'edit'])
        ->middleware(['module:lifecycle', 'permission:lifecycle_task_templates.update'])->name('settings.lifecycle-task-templates.edit');

    Route::get('settings/locations', [LocationUiController::class, 'index'])
        ->middleware('permission:locations.view')->name('settings.locations');
    Route::get('settings/locations/create', [LocationUiController::class, 'create'])
        ->middleware('permission:locations.create')->name('settings.locations.create');
    Route::get('settings/locations/{location}/edit', [LocationUiController::class, 'edit'])
        ->middleware('permission:locations.update')->name('settings.locations.edit');

    // Leave Types Admin UI (Checkpoint 25) — same thin-page-route
    // pattern, fetched client-side from the existing /api/v1/leave-types
    // endpoints (Checkpoint 12).
    Route::get('settings/leave-types', [LeaveTypeUiController::class, 'index'])
        ->middleware(['module:leave', 'permission:leave_types.view'])->name('settings.leave-types');
    Route::get('settings/leave-types/create', [LeaveTypeUiController::class, 'create'])
        ->middleware(['module:leave', 'permission:leave_types.create'])->name('settings.leave-types.create');
    Route::get('settings/leave-types/{leaveType}/edit', [LeaveTypeUiController::class, 'edit'])
        ->middleware(['module:leave', 'permission:leave_types.update'])->name('settings.leave-types.edit');

    Route::get('settings/security', fn () => Inertia::render('Settings/Security'))
        ->middleware('permission:audit.view')->name('settings.security');

    // Audit Log Viewing UI (Checkpoint 24) — thin page routes; audit log
    // data is fetched client-side from the new /api/v1/audit-logs
    // endpoints, never passed through as an Inertia prop (except the
    // tenant-checked auditLogId on the detail page). No write route
    // exists anywhere — audit logs are read-only.
    Route::get('settings/security/audit-logs', [AuditLogUiController::class, 'index'])
        ->middleware('permission:audit.view')->name('settings.security.audit-logs');
    Route::get('settings/security/audit-logs/{auditLog}', [AuditLogUiController::class, 'show'])
        ->middleware('permission:audit.view')->name('settings.security.audit-logs.show');
    // No dedicated "integrations.*" permission exists, and none is
    // invented for a page with no real content — falls back to the
    // same umbrella check as the landing page itself.
    Route::get('settings/integrations', fn () => Inertia::render('Settings/Integrations'))
        ->middleware('permission:tenant.settings.view')->name('settings.integrations');
});

require __DIR__.'/auth.php';
require __DIR__.'/api.php';
