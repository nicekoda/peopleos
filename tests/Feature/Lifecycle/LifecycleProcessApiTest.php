<?php

namespace Tests\Feature\Lifecycle;

use App\Enums\LifecycleProcessStatus;
use App\Models\Employee;
use App\Models\LifecycleProcess;
use App\Models\LifecycleTask;
use App\Models\LifecycleTaskTemplate;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class LifecycleProcessApiTest extends TestCase
{
    use RefreshDatabase;

    protected function userWithPermissions(Tenant $tenant, string ...$permissionKeys): User
    {
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        $role = Role::factory()->create(['tenant_id' => $tenant->id]);

        foreach ($permissionKeys as $key) {
            $permission = Permission::query()->firstOrCreate(
                ['key' => $key],
                ['category' => explode('.', $key)[0], 'is_platform_permission' => false],
            );
            $role->givePermissionTo($permission);
        }

        $user->assignRole($role);

        return $user;
    }

    protected function linkedUser(Tenant $tenant, string ...$permissionKeys): array
    {
        $user = $this->userWithPermissions($tenant, ...$permissionKeys);
        $employee = Employee::factory()->create(['tenant_id' => $tenant->id, 'user_id' => $user->id]);

        return [$user, $employee];
    }

    protected function url(Tenant $tenant, string $path): string
    {
        return 'http://'.$tenant->subdomain.'.'.config('tenancy.base_domain').'/api/v1/'.$path;
    }

    // 1: guest cannot access lifecycle API
    public function test_guest_cannot_access_lifecycle_api(): void
    {
        $tenant = Tenant::factory()->create();
        $process = LifecycleProcess::factory()->create(['tenant_id' => $tenant->id]);

        $this->getJson($this->url($tenant, 'lifecycle-processes'))->assertUnauthorized();
        $this->postJson($this->url($tenant, 'lifecycle-processes'), [])->assertUnauthorized();
        $this->getJson($this->url($tenant, "lifecycle-processes/{$process->id}"))->assertUnauthorized();
        $this->patchJson($this->url($tenant, "lifecycle-processes/{$process->id}"), [])->assertUnauthorized();
        $this->deleteJson($this->url($tenant, "lifecycle-processes/{$process->id}"))->assertUnauthorized();
    }

    // 2: user without lifecycle.view cannot list/view
    public function test_user_without_view_permission_cannot_list_or_view(): void
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        $process = LifecycleProcess::factory()->create(['tenant_id' => $tenant->id]);

        $this->actingAs($user)->getJson($this->url($tenant, 'lifecycle-processes'))->assertForbidden();
        $this->actingAs($user)->getJson($this->url($tenant, "lifecycle-processes/{$process->id}"))->assertForbidden();
    }

    // 3: user with lifecycle.view can list/view allowed records
    public function test_user_with_view_permission_can_list_and_view(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'lifecycle.view', 'lifecycle.create');
        $process = LifecycleProcess::factory()->create(['tenant_id' => $tenant->id]);

        $this->actingAs($user)->getJson($this->url($tenant, 'lifecycle-processes'))->assertOk();
        $this->actingAs($user)->getJson($this->url($tenant, "lifecycle-processes/{$process->id}"))->assertOk();
    }

    // 4: user without create cannot create process
    public function test_user_without_create_permission_cannot_create_process(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'lifecycle.view');
        $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);

        $response = $this->actingAs($user)->postJson($this->url($tenant, 'lifecycle-processes'), [
            'employee_id' => $employee->id,
            'type' => 'onboarding',
        ]);

        $response->assertForbidden();
    }

    // 5: user with create can create process
    public function test_user_with_create_permission_can_create_process(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'lifecycle.create');
        $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);

        $response = $this->actingAs($user)->postJson($this->url($tenant, 'lifecycle-processes'), [
            'employee_id' => $employee->id,
            'type' => 'onboarding',
        ]);

        $response->assertCreated();
        $this->assertDatabaseHas('employee_lifecycle_processes', [
            'tenant_id' => $tenant->id,
            'employee_id' => $employee->id,
            'type' => 'onboarding',
            'status' => 'draft',
        ]);
    }

    // 6: user cannot create process for another tenant's employee
    public function test_user_cannot_create_process_for_another_tenants_employee(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenantA, 'lifecycle.create');
        $employeeB = Employee::factory()->create(['tenant_id' => $tenantB->id]);

        $response = $this->actingAs($user)->postJson($this->url($tenantA, 'lifecycle-processes'), [
            'employee_id' => $employeeB->id,
            'type' => 'onboarding',
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('employee_id');
    }

    // 7: user without update permission cannot update process
    public function test_user_without_update_permission_cannot_update_process(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'lifecycle.view');
        $process = LifecycleProcess::factory()->create(['tenant_id' => $tenant->id]);

        $response = $this->actingAs($user)->patchJson($this->url($tenant, "lifecycle-processes/{$process->id}"), [
            'due_date' => now()->addWeek()->toDateString(),
        ]);

        $response->assertForbidden();
    }

    // 8: user with update permission can update process
    public function test_user_with_update_permission_can_update_process(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'lifecycle.update');
        $process = LifecycleProcess::factory()->create(['tenant_id' => $tenant->id]);

        $response = $this->actingAs($user)->patchJson($this->url($tenant, "lifecycle-processes/{$process->id}"), [
            'status' => 'in_progress',
        ]);

        $response->assertOk();
        $process->refresh();
        $this->assertSame('in_progress', $process->status->value);
        $this->assertNotNull($process->started_at);
    }

    // 9: user without delete permission cannot archive/cancel
    public function test_user_without_delete_permission_cannot_archive_process(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'lifecycle.view');
        $process = LifecycleProcess::factory()->create(['tenant_id' => $tenant->id]);

        $response = $this->actingAs($user)->deleteJson($this->url($tenant, "lifecycle-processes/{$process->id}"));

        $response->assertForbidden();
    }

    // 10: user with delete permission can archive/cancel
    public function test_user_with_delete_permission_can_archive_process(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'lifecycle.delete');
        $process = LifecycleProcess::factory()->create(['tenant_id' => $tenant->id]);

        $response = $this->actingAs($user)->deleteJson($this->url($tenant, "lifecycle-processes/{$process->id}"));

        $response->assertOk();
        $this->assertSame('cancelled', $process->fresh()->status->value);
        $this->assertSoftDeleted('employee_lifecycle_processes', ['id' => $process->id]);
    }

    // 11: Tenant A cannot view/update/archive Tenant B process
    public function test_tenant_a_cannot_view_update_or_archive_tenant_b_process(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();
        $userA = $this->userWithPermissions($tenantA, 'lifecycle.view', 'lifecycle.update', 'lifecycle.delete');
        $processB = LifecycleProcess::factory()->create(['tenant_id' => $tenantB->id]);

        $this->actingAs($userA)->getJson($this->url($tenantA, "lifecycle-processes/{$processB->id}"))->assertNotFound();
        $this->actingAs($userA)->patchJson($this->url($tenantA, "lifecycle-processes/{$processB->id}"), ['due_date' => now()->toDateString()])->assertNotFound();
        $this->actingAs($userA)->deleteJson($this->url($tenantA, "lifecycle-processes/{$processB->id}"))->assertNotFound();
    }

    public function test_tenant_a_cannot_list_tenant_b_processes(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();
        $userA = $this->userWithPermissions($tenantA, 'lifecycle.view', 'lifecycle.create', 'lifecycle.update', 'lifecycle.delete');
        LifecycleProcess::factory()->create(['tenant_id' => $tenantB->id]);

        $response = $this->actingAs($userA)->getJson($this->url($tenantA, 'lifecycle-processes'));

        $response->assertOk();
        $this->assertCount(0, $response->json('data'));
    }

    // 12: Platform Super Admin blocked
    public function test_platform_super_admin_is_blocked_from_lifecycle_api(): void
    {
        $tenant = Tenant::factory()->create();
        $admin = User::factory()->platformAdmin()->create();

        $response = $this->actingAs($admin)->getJson($this->url($tenant, 'lifecycle-processes'));

        $response->assertForbidden();
    }

    // 15/16: HR/Admin-tier visibility is unrestricted; Line Manager sees only direct reports
    public function test_line_manager_only_sees_direct_reports_processes_in_index(): void
    {
        $tenant = Tenant::factory()->create();
        [$manager, $managerEmployee] = $this->linkedUser($tenant, 'lifecycle.view', 'lifecycle.complete_task');
        $report = Employee::factory()->create(['tenant_id' => $tenant->id, 'manager_employee_id' => $managerEmployee->id]);
        $unrelated = Employee::factory()->create(['tenant_id' => $tenant->id]);

        $reportProcess = LifecycleProcess::factory()->create(['tenant_id' => $tenant->id, 'employee_id' => $report->id]);
        LifecycleProcess::factory()->create(['tenant_id' => $tenant->id, 'employee_id' => $unrelated->id]);

        $response = $this->actingAs($manager)->getJson($this->url($tenant, 'lifecycle-processes'));

        $response->assertOk();
        $ids = collect($response->json('data'))->pluck('id')->all();
        $this->assertSame([$reportProcess->id], $ids);
    }

    public function test_line_manager_cannot_view_unrelated_process(): void
    {
        $tenant = Tenant::factory()->create();
        [$manager] = $this->linkedUser($tenant, 'lifecycle.view', 'lifecycle.complete_task');
        $unrelated = Employee::factory()->create(['tenant_id' => $tenant->id]);
        $process = LifecycleProcess::factory()->create(['tenant_id' => $tenant->id, 'employee_id' => $unrelated->id]);

        $response = $this->actingAs($manager)->getJson($this->url($tenant, "lifecycle-processes/{$process->id}"));

        $response->assertNotFound();
    }

    public function test_line_manager_can_view_direct_reports_process(): void
    {
        $tenant = Tenant::factory()->create();
        [$manager, $managerEmployee] = $this->linkedUser($tenant, 'lifecycle.view', 'lifecycle.complete_task');
        $report = Employee::factory()->create(['tenant_id' => $tenant->id, 'manager_employee_id' => $managerEmployee->id]);
        $process = LifecycleProcess::factory()->create(['tenant_id' => $tenant->id, 'employee_id' => $report->id]);

        $response = $this->actingAs($manager)->getJson($this->url($tenant, "lifecycle-processes/{$process->id}"));

        $response->assertOk();
    }

    // Employee: no process-level access at all, only via an assigned task (see LifecycleTaskApiTest for task-level checks)
    public function test_employee_without_any_assigned_task_sees_no_processes(): void
    {
        $tenant = Tenant::factory()->create();
        [$user, $employee] = $this->linkedUser($tenant, 'lifecycle.view', 'lifecycle.complete_task');
        LifecycleProcess::factory()->create(['tenant_id' => $tenant->id, 'employee_id' => $employee->id]);

        $response = $this->actingAs($user)->getJson($this->url($tenant, 'lifecycle-processes'));

        $response->assertOk();
        $this->assertCount(0, $response->json('data'));
    }

    // 17: HR/Admin can manage tenant lifecycle processes tenant-wide
    public function test_hr_manager_sees_every_process_in_index(): void
    {
        $tenant = Tenant::factory()->create();
        $hrManager = $this->userWithPermissions($tenant, 'lifecycle.view', 'lifecycle.create', 'lifecycle.update', 'lifecycle.delete', 'lifecycle.assign_task', 'lifecycle.complete_task');
        LifecycleProcess::factory()->count(3)->create(['tenant_id' => $tenant->id]);

        $response = $this->actingAs($hrManager)->getJson($this->url($tenant, 'lifecycle-processes'));

        $response->assertOk();
        $this->assertCount(3, $response->json('data'));
    }

    // Auditor: view-only, tenant-wide (no complete_task at all)
    public function test_auditor_sees_every_process_but_cannot_mutate(): void
    {
        $tenant = Tenant::factory()->create();
        $auditor = $this->userWithPermissions($tenant, 'lifecycle.view');
        $process = LifecycleProcess::factory()->create(['tenant_id' => $tenant->id]);

        $this->actingAs($auditor)->getJson($this->url($tenant, 'lifecycle-processes'))->assertOk();
        $this->actingAs($auditor)->getJson($this->url($tenant, "lifecycle-processes/{$process->id}"))->assertOk();
        $this->actingAs($auditor)->patchJson($this->url($tenant, "lifecycle-processes/{$process->id}"), ['status' => 'in_progress'])->assertForbidden();
        $this->actingAs($auditor)->deleteJson($this->url($tenant, "lifecycle-processes/{$process->id}"))->assertForbidden();
    }

    // 18: completed/cancelled process blocks unsafe updates
    public function test_completed_process_rejects_further_updates(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'lifecycle.update');
        $process = LifecycleProcess::factory()->completed()->create(['tenant_id' => $tenant->id]);

        $response = $this->actingAs($user)->patchJson($this->url($tenant, "lifecycle-processes/{$process->id}"), [
            'due_date' => now()->addWeek()->toDateString(),
        ]);

        $response->assertStatus(422);
    }

    public function test_cancelled_process_rejects_further_updates(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'lifecycle.update');
        $process = LifecycleProcess::factory()->cancelled()->create(['tenant_id' => $tenant->id]);

        $response = $this->actingAs($user)->patchJson($this->url($tenant, "lifecycle-processes/{$process->id}"), [
            'status' => 'in_progress',
        ]);

        $response->assertStatus(422);
    }

    public function test_invalid_status_transition_is_rejected(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'lifecycle.update');
        $process = LifecycleProcess::factory()->create(['tenant_id' => $tenant->id, 'status' => LifecycleProcessStatus::Draft]);

        $response = $this->actingAs($user)->patchJson($this->url($tenant, "lifecycle-processes/{$process->id}"), [
            'status' => 'completed',
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('status');
    }

    // 19: resource safety — no internal fields exposed
    public function test_process_resource_does_not_expose_internal_fields(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'lifecycle.view');
        LifecycleProcess::factory()->create(['tenant_id' => $tenant->id]);

        $response = $this->actingAs($user)->getJson($this->url($tenant, 'lifecycle-processes'));
        $body = json_encode($response->json());

        $this->assertStringNotContainsString('"tenant_id"', $body);
        $this->assertStringNotContainsString('"created_by"', $body);
        $this->assertStringNotContainsString('"updated_by"', $body);
        $this->assertStringNotContainsString('"deleted_at"', $body);
    }

    // Employee_id/type immutable after create — not in UpdateLifecycleProcessRequest at all
    public function test_employee_id_and_type_cannot_be_changed_after_create(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'lifecycle.update');
        $process = LifecycleProcess::factory()->create(['tenant_id' => $tenant->id, 'type' => 'onboarding']);
        $otherEmployee = Employee::factory()->create(['tenant_id' => $tenant->id]);

        $this->actingAs($user)->patchJson($this->url($tenant, "lifecycle-processes/{$process->id}"), [
            'employee_id' => $otherEmployee->id,
            'type' => 'offboarding',
        ])->assertOk();

        $process->refresh();
        $this->assertNotSame($otherEmployee->id, $process->employee_id);
        $this->assertSame('onboarding', $process->type->value);
    }

    // Forged system fields cannot be set via create/update
    public function test_forged_system_fields_are_ignored_on_create(): void
    {
        $tenant = Tenant::factory()->create();
        $otherTenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'lifecycle.create');
        $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);
        $otherUser = User::factory()->create(['tenant_id' => $otherTenant->id]);

        $response = $this->actingAs($user)->postJson($this->url($tenant, 'lifecycle-processes'), [
            'employee_id' => $employee->id,
            'type' => 'onboarding',
            'tenant_id' => $otherTenant->id,
            'status' => 'completed',
            'completed_at' => now()->toDateTimeString(),
            'created_by' => $otherUser->id,
        ]);

        $response->assertCreated();
        $process = LifecycleProcess::query()->findOrFail($response->json('data.id'));
        $this->assertSame($tenant->id, $process->tenant_id);
        $this->assertSame('draft', $process->status->value);
        $this->assertNull($process->completed_at);
        $this->assertSame($user->id, $process->created_by);
    }

    // 20: create/update/cancel write audit logs
    public function test_create_process_writes_audit_log(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'lifecycle.create');
        $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);

        $this->actingAs($user)->postJson($this->url($tenant, 'lifecycle-processes'), [
            'employee_id' => $employee->id,
            'type' => 'offboarding',
        ])->assertCreated();

        $this->assertDatabaseHas('audit_logs', ['action' => 'lifecycle_process.created', 'module' => 'lifecycle', 'actor_user_id' => $user->id]);
    }

    public function test_update_process_writes_audit_log(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'lifecycle.update');
        $process = LifecycleProcess::factory()->create(['tenant_id' => $tenant->id]);

        $this->actingAs($user)->patchJson($this->url($tenant, "lifecycle-processes/{$process->id}"), [
            'due_date' => now()->addWeek()->toDateString(),
        ])->assertOk();

        $this->assertDatabaseHas('audit_logs', ['action' => 'lifecycle_process.updated', 'module' => 'lifecycle', 'actor_user_id' => $user->id]);
    }

    public function test_complete_process_writes_audit_log(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'lifecycle.update');
        $process = LifecycleProcess::factory()->inProgress()->create(['tenant_id' => $tenant->id]);

        $this->actingAs($user)->patchJson($this->url($tenant, "lifecycle-processes/{$process->id}"), [
            'status' => 'completed',
        ])->assertOk();

        $this->assertDatabaseHas('audit_logs', ['action' => 'lifecycle_process.completed', 'module' => 'lifecycle', 'actor_user_id' => $user->id]);
    }

    public function test_cancel_process_writes_audit_log(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'lifecycle.delete');
        $process = LifecycleProcess::factory()->create(['tenant_id' => $tenant->id]);

        $this->actingAs($user)->deleteJson($this->url($tenant, "lifecycle-processes/{$process->id}"))->assertOk();

        $this->assertDatabaseHas('audit_logs', ['action' => 'lifecycle_process.cancelled', 'module' => 'lifecycle', 'actor_user_id' => $user->id]);
    }

    // 33/34-equivalent: inactive user / inactive tenant fail closed
    public function test_inactive_user_fails_closed(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'lifecycle.view');
        $user->update(['status' => User::STATUS_INACTIVE]);

        $response = $this->actingAs($user)->getJson($this->url($tenant, 'lifecycle-processes'));

        $response->assertForbidden();
    }

    public function test_user_under_inactive_tenant_fails_closed(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'lifecycle.view');
        $tenant->update(['status' => Tenant::STATUS_SUSPENDED]);

        $response = $this->actingAs($user)->getJson($this->url($tenant, 'lifecycle-processes'));

        $response->assertForbidden();
    }

    // No hard-delete route exists for processes
    public function test_no_hard_delete_ever_removes_the_row(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'lifecycle.delete');
        $process = LifecycleProcess::factory()->create(['tenant_id' => $tenant->id]);

        $this->actingAs($user)->deleteJson($this->url($tenant, "lifecycle-processes/{$process->id}"))->assertOk();

        $this->assertDatabaseHas('employee_lifecycle_processes', ['id' => $process->id]);
    }

    // Checkpoint 42 — creating a process applies matching same-tenant,
    // same-type templates as real tasks.
    public function test_creating_a_process_applies_matching_task_templates(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'lifecycle.create');
        $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);
        LifecycleTaskTemplate::factory()->onboarding()->create([
            'tenant_id' => $tenant->id, 'title' => 'Send welcome email', 'due_in_days' => 0, 'sort_order' => 10,
        ]);
        LifecycleTaskTemplate::factory()->onboarding()->create([
            'tenant_id' => $tenant->id, 'title' => 'Schedule orientation', 'due_in_days' => 5, 'sort_order' => 20,
        ]);
        // A different type's template must never be applied.
        LifecycleTaskTemplate::factory()->offboarding()->create([
            'tenant_id' => $tenant->id, 'title' => 'Revoke access',
        ]);

        $response = $this->actingAs($user)->postJson($this->url($tenant, 'lifecycle-processes'), [
            'employee_id' => $employee->id,
            'type' => 'onboarding',
        ]);

        $response->assertCreated();
        $processId = $response->json('data.id');

        // due_date is a 'date' cast — assertDatabaseHas compares raw DB
        // strings (which include a time portion, e.g. "2026-07-10
        // 00:00:00"), so fetch the models and compare the cast Carbon
        // value instead of a fragile raw-string match.
        $welcomeTask = LifecycleTask::query()->where('process_id', $processId)->where('title', 'Send welcome email')->firstOrFail();
        $this->assertTrue($welcomeTask->due_date->isSameDay(now()));
        $this->assertSame('pending', $welcomeTask->status->value);

        $orientationTask = LifecycleTask::query()->where('process_id', $processId)->where('title', 'Schedule orientation')->firstOrFail();
        $this->assertTrue($orientationTask->due_date->isSameDay(now()->addDays(5)));

        $this->assertDatabaseMissing('employee_lifecycle_tasks', ['process_id' => $processId, 'title' => 'Revoke access']);
        $this->assertSame(2, LifecycleTask::query()->where('process_id', $processId)->count());
    }

    // No templates for this tenant/type — process still creates fine, just empty
    public function test_creating_a_process_with_no_matching_templates_creates_no_tasks(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'lifecycle.create');
        $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);

        $response = $this->actingAs($user)->postJson($this->url($tenant, 'lifecycle-processes'), [
            'employee_id' => $employee->id,
            'type' => 'onboarding',
        ]);

        $response->assertCreated();
        $this->assertDatabaseCount('employee_lifecycle_tasks', 0);
    }

    // Archived templates are never applied to newly created processes
    public function test_archived_templates_are_not_applied_to_new_processes(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'lifecycle.create');
        $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);
        LifecycleTaskTemplate::factory()->onboarding()->create(['tenant_id' => $tenant->id, 'title' => 'Archived task'])->delete();

        $response = $this->actingAs($user)->postJson($this->url($tenant, 'lifecycle-processes'), [
            'employee_id' => $employee->id,
            'type' => 'onboarding',
        ]);

        $response->assertCreated();
        $this->assertDatabaseCount('employee_lifecycle_tasks', 0);
    }

    // Another tenant's templates, even for the same type, are never applied
    public function test_another_tenants_templates_are_not_applied(): void
    {
        $tenant = Tenant::factory()->create();
        $otherTenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'lifecycle.create');
        $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);
        LifecycleTaskTemplate::factory()->onboarding()->create(['tenant_id' => $otherTenant->id, 'title' => 'Other tenant task']);

        $response = $this->actingAs($user)->postJson($this->url($tenant, 'lifecycle-processes'), [
            'employee_id' => $employee->id,
            'type' => 'onboarding',
        ]);

        $response->assertCreated();
        $this->assertDatabaseCount('employee_lifecycle_tasks', 0);
    }

    // Applying templates writes its own audit log entry, distinct from lifecycle_process.created
    public function test_applying_templates_writes_audit_log(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'lifecycle.create');
        $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);
        LifecycleTaskTemplate::factory()->onboarding()->create(['tenant_id' => $tenant->id]);

        $this->actingAs($user)->postJson($this->url($tenant, 'lifecycle-processes'), [
            'employee_id' => $employee->id,
            'type' => 'onboarding',
        ])->assertCreated();

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'lifecycle_process.tasks_applied_from_templates',
            'module' => 'lifecycle',
            'actor_user_id' => $user->id,
        ]);
    }

    // All lifecycle-processes routes carry tenant.matches
    public function test_all_lifecycle_process_routes_include_tenant_matches_middleware(): void
    {
        $uris = [
            'api/v1/lifecycle-processes',
            'api/v1/lifecycle-processes/{lifecycleProcess}',
        ];

        $routes = collect(Route::getRoutes())->filter(fn ($route) => in_array($route->uri(), $uris));

        $this->assertGreaterThanOrEqual(count($uris), $routes->count());

        foreach ($routes as $route) {
            $this->assertContains(
                'tenant.matches',
                $route->gatherMiddleware(),
                "Route [{$route->methods()[0]} {$route->uri()}] is missing tenant.matches middleware.",
            );
        }
    }
}
