<?php

namespace Tests\Feature\Lifecycle;

use App\Enums\LifecycleTaskStatus;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\LifecycleProcess;
use App\Models\LifecycleTask;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class LifecycleTaskApiTest extends TestCase
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

    // 1: guest cannot access task API
    public function test_guest_cannot_access_task_api(): void
    {
        $tenant = Tenant::factory()->create();
        $process = LifecycleProcess::factory()->create(['tenant_id' => $tenant->id]);
        $task = LifecycleTask::factory()->create(['tenant_id' => $tenant->id, 'process_id' => $process->id]);

        $this->postJson($this->url($tenant, "lifecycle-processes/{$process->id}/tasks"), [])->assertUnauthorized();
        $this->patchJson($this->url($tenant, "lifecycle-tasks/{$task->id}"), [])->assertUnauthorized();
        $this->deleteJson($this->url($tenant, "lifecycle-tasks/{$task->id}"))->assertUnauthorized();
        $this->postJson($this->url($tenant, "lifecycle-tasks/{$task->id}/complete"))->assertUnauthorized();
        $this->postJson($this->url($tenant, "lifecycle-tasks/{$task->id}/skip"))->assertUnauthorized();
    }

    // 13: task create/update requires permission
    public function test_user_without_create_permission_cannot_create_task(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'lifecycle.view');
        $process = LifecycleProcess::factory()->create(['tenant_id' => $tenant->id]);

        $response = $this->actingAs($user)->postJson($this->url($tenant, "lifecycle-processes/{$process->id}/tasks"), [
            'title' => 'Set up laptop',
        ]);

        $response->assertForbidden();
    }

    public function test_user_with_create_permission_can_create_task(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'lifecycle.create');
        $process = LifecycleProcess::factory()->create(['tenant_id' => $tenant->id]);

        $response = $this->actingAs($user)->postJson($this->url($tenant, "lifecycle-processes/{$process->id}/tasks"), [
            'title' => 'Set up laptop',
        ]);

        $response->assertCreated();
        $this->assertDatabaseHas('employee_lifecycle_tasks', [
            'tenant_id' => $tenant->id,
            'process_id' => $process->id,
            'title' => 'Set up laptop',
            'status' => 'pending',
        ]);
    }

    public function test_user_without_update_permission_cannot_update_task(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'lifecycle.view');
        $process = LifecycleProcess::factory()->create(['tenant_id' => $tenant->id]);
        $task = LifecycleTask::factory()->create(['tenant_id' => $tenant->id, 'process_id' => $process->id]);

        $response = $this->actingAs($user)->patchJson($this->url($tenant, "lifecycle-tasks/{$task->id}"), [
            'title' => 'Renamed',
        ]);

        $response->assertForbidden();
    }

    public function test_user_with_update_permission_can_update_task(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'lifecycle.update');
        $process = LifecycleProcess::factory()->create(['tenant_id' => $tenant->id]);
        $task = LifecycleTask::factory()->create(['tenant_id' => $tenant->id, 'process_id' => $process->id]);

        $response = $this->actingAs($user)->patchJson($this->url($tenant, "lifecycle-tasks/{$task->id}"), [
            'title' => 'Renamed task',
        ]);

        $response->assertOk();
        $this->assertSame('Renamed task', $task->fresh()->title);
    }

    // 14: task assignee must belong to same tenant
    public function test_task_assignee_must_belong_to_same_tenant(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenantA, 'lifecycle.create', 'lifecycle.assign_task');
        $process = LifecycleProcess::factory()->create(['tenant_id' => $tenantA->id]);
        $userB = User::factory()->create(['tenant_id' => $tenantB->id]);

        $response = $this->actingAs($user)->postJson($this->url($tenantA, "lifecycle-processes/{$process->id}/tasks"), [
            'title' => 'Cross-tenant assignment attempt',
            'assigned_to_user_id' => $userB->id,
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('assigned_to_user_id');
    }

    public function test_assigning_a_task_requires_assign_task_permission(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'lifecycle.create');
        $process = LifecycleProcess::factory()->create(['tenant_id' => $tenant->id]);
        $assignee = User::factory()->create(['tenant_id' => $tenant->id]);

        $response = $this->actingAs($user)->postJson($this->url($tenant, "lifecycle-processes/{$process->id}/tasks"), [
            'title' => 'Needs assign_task permission',
            'assigned_to_user_id' => $assignee->id,
        ]);

        $response->assertForbidden();
    }

    public function test_user_with_assign_task_permission_can_assign_on_create(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'lifecycle.create', 'lifecycle.assign_task');
        $process = LifecycleProcess::factory()->create(['tenant_id' => $tenant->id]);
        $assignee = User::factory()->create(['tenant_id' => $tenant->id]);

        $response = $this->actingAs($user)->postJson($this->url($tenant, "lifecycle-processes/{$process->id}/tasks"), [
            'title' => 'Assigned task',
            'assigned_to_user_id' => $assignee->id,
        ]);

        $response->assertCreated();
        $this->assertDatabaseHas('employee_lifecycle_tasks', ['assigned_to_user_id' => $assignee->id]);
    }

    // 15: Employee can only view/complete assigned tasks
    public function test_employee_can_complete_own_assigned_task(): void
    {
        $tenant = Tenant::factory()->create();
        [$user] = $this->linkedUser($tenant, 'lifecycle.view', 'lifecycle.complete_task');
        $process = LifecycleProcess::factory()->inProgress()->create(['tenant_id' => $tenant->id]);
        $task = LifecycleTask::factory()->create(['tenant_id' => $tenant->id, 'process_id' => $process->id, 'assigned_to_user_id' => $user->id]);

        $response = $this->actingAs($user)->postJson($this->url($tenant, "lifecycle-tasks/{$task->id}/complete"));

        $response->assertOk();
        $task->refresh();
        $this->assertSame('completed', $task->status->value);
        $this->assertSame($user->id, $task->completed_by);
        $this->assertNotNull($task->completed_at);
    }

    public function test_employee_cannot_complete_a_task_assigned_to_someone_else(): void
    {
        $tenant = Tenant::factory()->create();
        [$user] = $this->linkedUser($tenant, 'lifecycle.view', 'lifecycle.complete_task');
        $otherUser = User::factory()->create(['tenant_id' => $tenant->id]);
        $process = LifecycleProcess::factory()->inProgress()->create(['tenant_id' => $tenant->id]);
        $task = LifecycleTask::factory()->create(['tenant_id' => $tenant->id, 'process_id' => $process->id, 'assigned_to_user_id' => $otherUser->id]);

        $response = $this->actingAs($user)->postJson($this->url($tenant, "lifecycle-tasks/{$task->id}/complete"));

        $response->assertForbidden();
        $this->assertSame('pending', $task->fresh()->status->value);
    }

    public function test_employee_can_skip_own_assigned_task(): void
    {
        $tenant = Tenant::factory()->create();
        [$user] = $this->linkedUser($tenant, 'lifecycle.view', 'lifecycle.complete_task');
        $process = LifecycleProcess::factory()->inProgress()->create(['tenant_id' => $tenant->id]);
        $task = LifecycleTask::factory()->create(['tenant_id' => $tenant->id, 'process_id' => $process->id, 'assigned_to_user_id' => $user->id]);

        $response = $this->actingAs($user)->postJson($this->url($tenant, "lifecycle-tasks/{$task->id}/skip"));

        $response->assertOk();
        $this->assertSame('skipped', $task->fresh()->status->value);
    }

    // 16: Line Manager visibility is properly scoped — direct report's task, even if unassigned to the manager
    public function test_line_manager_can_complete_task_within_direct_reports_process(): void
    {
        $tenant = Tenant::factory()->create();
        [$manager, $managerEmployee] = $this->linkedUser($tenant, 'lifecycle.view', 'lifecycle.complete_task');
        $report = Employee::factory()->create(['tenant_id' => $tenant->id, 'manager_employee_id' => $managerEmployee->id]);
        $process = LifecycleProcess::factory()->inProgress()->create(['tenant_id' => $tenant->id, 'employee_id' => $report->id]);
        $task = LifecycleTask::factory()->create(['tenant_id' => $tenant->id, 'process_id' => $process->id]);

        $response = $this->actingAs($manager)->postJson($this->url($tenant, "lifecycle-tasks/{$task->id}/complete"));

        $response->assertOk();
    }

    public function test_line_manager_cannot_complete_task_for_unrelated_employee(): void
    {
        $tenant = Tenant::factory()->create();
        [$manager] = $this->linkedUser($tenant, 'lifecycle.view', 'lifecycle.complete_task');
        $unrelated = Employee::factory()->create(['tenant_id' => $tenant->id]);
        $process = LifecycleProcess::factory()->inProgress()->create(['tenant_id' => $tenant->id, 'employee_id' => $unrelated->id]);
        $task = LifecycleTask::factory()->create(['tenant_id' => $tenant->id, 'process_id' => $process->id]);

        $response = $this->actingAs($manager)->postJson($this->url($tenant, "lifecycle-tasks/{$task->id}/complete"));

        $response->assertForbidden();
    }

    // 17: HR/Admin can complete any task tenant-wide
    public function test_hr_manager_can_complete_any_task(): void
    {
        $tenant = Tenant::factory()->create();
        $hrManager = $this->userWithPermissions($tenant, 'lifecycle.view', 'lifecycle.complete_task', 'lifecycle.update');
        $process = LifecycleProcess::factory()->inProgress()->create(['tenant_id' => $tenant->id]);
        $task = LifecycleTask::factory()->create(['tenant_id' => $tenant->id, 'process_id' => $process->id]);

        $response = $this->actingAs($hrManager)->postJson($this->url($tenant, "lifecycle-tasks/{$task->id}/complete"));

        $response->assertOk();
    }

    // 18: completed/cancelled process blocks task mutations
    public function test_cannot_add_task_to_a_completed_process(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'lifecycle.create');
        $process = LifecycleProcess::factory()->completed()->create(['tenant_id' => $tenant->id]);

        $response = $this->actingAs($user)->postJson($this->url($tenant, "lifecycle-processes/{$process->id}/tasks"), [
            'title' => 'Too late',
        ]);

        $response->assertStatus(422);
    }

    public function test_cannot_complete_a_task_on_a_cancelled_process(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'lifecycle.complete_task');
        $process = LifecycleProcess::factory()->create(['tenant_id' => $tenant->id]);
        $task = LifecycleTask::factory()->create(['tenant_id' => $tenant->id, 'process_id' => $process->id, 'assigned_to_user_id' => $user->id]);
        $process->update(['status' => 'cancelled']);

        $response = $this->actingAs($user)->postJson($this->url($tenant, "lifecycle-tasks/{$task->id}/complete"));

        $response->assertStatus(422);
    }

    public function test_cannot_update_an_already_completed_task(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'lifecycle.update');
        $process = LifecycleProcess::factory()->inProgress()->create(['tenant_id' => $tenant->id]);
        $task = LifecycleTask::factory()->completed()->create(['tenant_id' => $tenant->id, 'process_id' => $process->id]);

        $response = $this->actingAs($user)->patchJson($this->url($tenant, "lifecycle-tasks/{$task->id}"), [
            'title' => 'Trying to edit a completed task',
        ]);

        $response->assertStatus(422);
    }

    public function test_invalid_task_status_transition_is_rejected(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'lifecycle.update');
        $process = LifecycleProcess::factory()->inProgress()->create(['tenant_id' => $tenant->id]);
        $task = LifecycleTask::factory()->skipped()->create(['tenant_id' => $tenant->id, 'process_id' => $process->id]);

        $response = $this->actingAs($user)->patchJson($this->url($tenant, "lifecycle-tasks/{$task->id}"), [
            'status' => 'pending',
        ]);

        $response->assertStatus(422);
    }

    public function test_update_endpoint_cannot_be_used_to_set_completed_status_directly(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'lifecycle.update');
        $process = LifecycleProcess::factory()->inProgress()->create(['tenant_id' => $tenant->id]);
        $task = LifecycleTask::factory()->create(['tenant_id' => $tenant->id, 'process_id' => $process->id]);

        $response = $this->actingAs($user)->patchJson($this->url($tenant, "lifecycle-tasks/{$task->id}"), [
            'status' => 'completed',
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('status');
        $this->assertSame('pending', $task->fresh()->status->value);
    }

    // Tenant isolation for tasks
    public function test_tenant_a_cannot_update_or_complete_tenant_b_task(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();
        $userA = $this->userWithPermissions($tenantA, 'lifecycle.update', 'lifecycle.complete_task', 'lifecycle.delete');
        $processB = LifecycleProcess::factory()->inProgress()->create(['tenant_id' => $tenantB->id]);
        $taskB = LifecycleTask::factory()->create(['tenant_id' => $tenantB->id, 'process_id' => $processB->id]);

        $this->actingAs($userA)->patchJson($this->url($tenantA, "lifecycle-tasks/{$taskB->id}"), ['title' => 'Hack'])->assertNotFound();
        $this->actingAs($userA)->postJson($this->url($tenantA, "lifecycle-tasks/{$taskB->id}/complete"))->assertNotFound();
        $this->actingAs($userA)->deleteJson($this->url($tenantA, "lifecycle-tasks/{$taskB->id}"))->assertNotFound();
    }

    public function test_platform_super_admin_is_blocked_from_task_api(): void
    {
        $tenant = Tenant::factory()->create();
        $admin = User::factory()->platformAdmin()->create();
        $process = LifecycleProcess::factory()->create(['tenant_id' => $tenant->id]);

        $response = $this->actingAs($admin)->postJson($this->url($tenant, "lifecycle-processes/{$process->id}/tasks"), ['title' => 'x']);

        $response->assertForbidden();
    }

    // 9/10-equivalent: delete permission for tasks
    public function test_user_without_delete_permission_cannot_remove_task(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'lifecycle.view');
        $process = LifecycleProcess::factory()->create(['tenant_id' => $tenant->id]);
        $task = LifecycleTask::factory()->create(['tenant_id' => $tenant->id, 'process_id' => $process->id]);

        $response = $this->actingAs($user)->deleteJson($this->url($tenant, "lifecycle-tasks/{$task->id}"));

        $response->assertForbidden();
    }

    public function test_user_with_delete_permission_can_remove_task(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'lifecycle.delete');
        $process = LifecycleProcess::factory()->create(['tenant_id' => $tenant->id]);
        $task = LifecycleTask::factory()->create(['tenant_id' => $tenant->id, 'process_id' => $process->id]);

        $response = $this->actingAs($user)->deleteJson($this->url($tenant, "lifecycle-tasks/{$task->id}"));

        $response->assertOk();
        $this->assertSoftDeleted('employee_lifecycle_tasks', ['id' => $task->id]);
    }

    // 19: resource safety
    public function test_task_resource_does_not_expose_internal_fields(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'lifecycle.create');
        $process = LifecycleProcess::factory()->create(['tenant_id' => $tenant->id]);

        $response = $this->actingAs($user)->postJson($this->url($tenant, "lifecycle-processes/{$process->id}/tasks"), [
            'title' => 'Check resource shape',
        ]);
        $body = json_encode($response->json());

        $this->assertStringNotContainsString('"tenant_id"', $body);
        $this->assertStringNotContainsString('"created_by"', $body);
        $this->assertStringNotContainsString('"updated_by"', $body);
        $this->assertStringNotContainsString('"completed_by"', $body);
        $this->assertStringNotContainsString('"deleted_at"', $body);
    }

    // Forged fields ignored
    public function test_forged_fields_are_ignored_on_task_create(): void
    {
        $tenant = Tenant::factory()->create();
        $otherTenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'lifecycle.create');
        $process = LifecycleProcess::factory()->create(['tenant_id' => $tenant->id]);
        $otherProcess = LifecycleProcess::factory()->create(['tenant_id' => $otherTenant->id]);

        $response = $this->actingAs($user)->postJson($this->url($tenant, "lifecycle-processes/{$process->id}/tasks"), [
            'title' => 'Forged fields test',
            'process_id' => $otherProcess->id,
            'tenant_id' => $otherTenant->id,
            'status' => 'completed',
            'completed_at' => now()->toDateTimeString(),
            'completed_by' => $user->id,
        ]);

        $response->assertCreated();
        $task = LifecycleTask::query()->findOrFail($response->json('data.id'));
        $this->assertSame($process->id, $task->process_id);
        $this->assertSame($tenant->id, $task->tenant_id);
        $this->assertSame('pending', $task->status->value);
        $this->assertNull($task->completed_at);
        $this->assertNull($task->completed_by);
    }

    // 20: create/update/complete/skip/delete write audit logs
    public function test_create_task_writes_audit_log(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'lifecycle.create');
        $process = LifecycleProcess::factory()->create(['tenant_id' => $tenant->id]);

        $this->actingAs($user)->postJson($this->url($tenant, "lifecycle-processes/{$process->id}/tasks"), [
            'title' => 'Audit me',
        ])->assertCreated();

        $this->assertDatabaseHas('audit_logs', ['action' => 'lifecycle_task.created', 'module' => 'lifecycle', 'actor_user_id' => $user->id]);
    }

    public function test_update_task_writes_audit_log(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'lifecycle.update');
        $process = LifecycleProcess::factory()->create(['tenant_id' => $tenant->id]);
        $task = LifecycleTask::factory()->create(['tenant_id' => $tenant->id, 'process_id' => $process->id]);

        $this->actingAs($user)->patchJson($this->url($tenant, "lifecycle-tasks/{$task->id}"), ['title' => 'Renamed'])->assertOk();

        $this->assertDatabaseHas('audit_logs', ['action' => 'lifecycle_task.updated', 'module' => 'lifecycle', 'actor_user_id' => $user->id]);
    }

    public function test_complete_task_writes_audit_log(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'lifecycle.complete_task');
        $process = LifecycleProcess::factory()->inProgress()->create(['tenant_id' => $tenant->id]);
        $task = LifecycleTask::factory()->create(['tenant_id' => $tenant->id, 'process_id' => $process->id, 'assigned_to_user_id' => $user->id]);

        $this->actingAs($user)->postJson($this->url($tenant, "lifecycle-tasks/{$task->id}/complete"))->assertOk();

        $this->assertDatabaseHas('audit_logs', ['action' => 'lifecycle_task.completed', 'module' => 'lifecycle', 'actor_user_id' => $user->id]);
    }

    public function test_skip_task_writes_audit_log(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'lifecycle.complete_task');
        $process = LifecycleProcess::factory()->inProgress()->create(['tenant_id' => $tenant->id]);
        $task = LifecycleTask::factory()->create(['tenant_id' => $tenant->id, 'process_id' => $process->id, 'assigned_to_user_id' => $user->id]);

        $this->actingAs($user)->postJson($this->url($tenant, "lifecycle-tasks/{$task->id}/skip"))->assertOk();

        $this->assertDatabaseHas('audit_logs', ['action' => 'lifecycle_task.skipped', 'module' => 'lifecycle', 'actor_user_id' => $user->id]);
    }

    public function test_delete_task_writes_audit_log(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'lifecycle.delete');
        $process = LifecycleProcess::factory()->create(['tenant_id' => $tenant->id]);
        $task = LifecycleTask::factory()->create(['tenant_id' => $tenant->id, 'process_id' => $process->id]);

        $this->actingAs($user)->deleteJson($this->url($tenant, "lifecycle-tasks/{$task->id}"))->assertOk();

        $this->assertDatabaseHas('audit_logs', ['action' => 'lifecycle_task.deleted', 'module' => 'lifecycle', 'actor_user_id' => $user->id]);
    }

    // Task descriptions (free text) are not exposed in audit metadata
    public function test_task_description_is_not_stored_in_audit_metadata(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'lifecycle.create');
        $process = LifecycleProcess::factory()->create(['tenant_id' => $tenant->id]);
        $secretDetail = 'Confidential exit interview note about workplace conflict.';

        $this->actingAs($user)->postJson($this->url($tenant, "lifecycle-processes/{$process->id}/tasks"), [
            'title' => 'Exit interview',
            'description' => $secretDetail,
        ])->assertCreated();

        $auditLog = AuditLog::query()->where('action', 'lifecycle_task.created')->firstOrFail();

        $this->assertStringNotContainsString($secretDetail, json_encode($auditLog->metadata));
        $this->assertStringNotContainsString($secretDetail, json_encode($auditLog->new_values));
    }

    public function test_all_lifecycle_task_routes_include_tenant_matches_middleware(): void
    {
        $uris = [
            'api/v1/lifecycle-processes/{lifecycleProcess}/tasks',
            'api/v1/lifecycle-processes/{lifecycleProcess}/tasks/reorder',
            'api/v1/lifecycle-tasks/{lifecycleTask}',
            'api/v1/lifecycle-tasks/{lifecycleTask}/complete',
            'api/v1/lifecycle-tasks/{lifecycleTask}/skip',
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

    public function test_task_status_enum_transitions_directly(): void
    {
        $this->assertTrue(LifecycleTaskStatus::Pending->canTransitionTo(LifecycleTaskStatus::Completed));
        $this->assertTrue(LifecycleTaskStatus::Pending->canTransitionTo(LifecycleTaskStatus::Skipped));
        $this->assertFalse(LifecycleTaskStatus::Completed->canTransitionTo(LifecycleTaskStatus::Pending));
        $this->assertFalse(LifecycleTaskStatus::Skipped->canTransitionTo(LifecycleTaskStatus::Completed));
    }

    // Checkpoint 45 — task ordering

    public function test_new_manual_task_is_appended_to_end_of_sort_order(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'lifecycle.create');
        $process = LifecycleProcess::factory()->create(['tenant_id' => $tenant->id]);
        LifecycleTask::factory()->create(['tenant_id' => $tenant->id, 'process_id' => $process->id, 'sort_order' => 5]);

        $response = $this->actingAs($user)->postJson($this->url($tenant, "lifecycle-processes/{$process->id}/tasks"), [
            'title' => 'Appended task',
        ]);

        $response->assertCreated();
        $task = LifecycleTask::query()->findOrFail($response->json('data.id'));
        $this->assertSame(6, $task->sort_order);
    }

    public function test_user_without_update_permission_cannot_reorder_tasks(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'lifecycle.view');
        $process = LifecycleProcess::factory()->create(['tenant_id' => $tenant->id]);
        $taskA = LifecycleTask::factory()->create(['tenant_id' => $tenant->id, 'process_id' => $process->id, 'sort_order' => 0]);
        $taskB = LifecycleTask::factory()->create(['tenant_id' => $tenant->id, 'process_id' => $process->id, 'sort_order' => 1]);

        $response = $this->actingAs($user)->postJson($this->url($tenant, "lifecycle-processes/{$process->id}/tasks/reorder"), [
            'task_ids' => [$taskB->id, $taskA->id],
        ]);

        $response->assertForbidden();
    }

    public function test_user_with_update_permission_can_reorder_tasks(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'lifecycle.update');
        $process = LifecycleProcess::factory()->create(['tenant_id' => $tenant->id]);
        $taskA = LifecycleTask::factory()->create(['tenant_id' => $tenant->id, 'process_id' => $process->id, 'sort_order' => 0]);
        $taskB = LifecycleTask::factory()->create(['tenant_id' => $tenant->id, 'process_id' => $process->id, 'sort_order' => 1]);
        $taskC = LifecycleTask::factory()->create(['tenant_id' => $tenant->id, 'process_id' => $process->id, 'sort_order' => 2]);

        $response = $this->actingAs($user)->postJson($this->url($tenant, "lifecycle-processes/{$process->id}/tasks/reorder"), [
            'task_ids' => [$taskC->id, $taskA->id, $taskB->id],
        ]);

        $response->assertOk();
        $this->assertSame(1, $taskA->fresh()->sort_order);
        $this->assertSame(2, $taskB->fresh()->sort_order);
        $this->assertSame(0, $taskC->fresh()->sort_order);

        $orderedIds = LifecycleProcess::query()->find($process->id)->tasks()->pluck('id')->all();
        $this->assertSame([$taskC->id, $taskA->id, $taskB->id], $orderedIds);
    }

    public function test_reorder_rejects_task_ids_not_belonging_to_process(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'lifecycle.update');
        $process = LifecycleProcess::factory()->create(['tenant_id' => $tenant->id]);
        $otherProcess = LifecycleProcess::factory()->create(['tenant_id' => $tenant->id]);
        $task = LifecycleTask::factory()->create(['tenant_id' => $tenant->id, 'process_id' => $process->id]);
        $foreignTask = LifecycleTask::factory()->create(['tenant_id' => $tenant->id, 'process_id' => $otherProcess->id]);

        $response = $this->actingAs($user)->postJson($this->url($tenant, "lifecycle-processes/{$process->id}/tasks/reorder"), [
            'task_ids' => [$foreignTask->id, $task->id],
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('task_ids');
        $this->assertSame(0, $task->fresh()->sort_order);
    }

    public function test_reorder_rejects_an_incomplete_task_id_list(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'lifecycle.update');
        $process = LifecycleProcess::factory()->create(['tenant_id' => $tenant->id]);
        $taskA = LifecycleTask::factory()->create(['tenant_id' => $tenant->id, 'process_id' => $process->id, 'sort_order' => 0]);
        LifecycleTask::factory()->create(['tenant_id' => $tenant->id, 'process_id' => $process->id, 'sort_order' => 1]);

        $response = $this->actingAs($user)->postJson($this->url($tenant, "lifecycle-processes/{$process->id}/tasks/reorder"), [
            'task_ids' => [$taskA->id],
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('task_ids');
    }

    public function test_cannot_reorder_tasks_on_a_completed_process(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'lifecycle.update');
        $process = LifecycleProcess::factory()->completed()->create(['tenant_id' => $tenant->id]);
        $taskA = LifecycleTask::factory()->create(['tenant_id' => $tenant->id, 'process_id' => $process->id, 'sort_order' => 0]);
        $taskB = LifecycleTask::factory()->create(['tenant_id' => $tenant->id, 'process_id' => $process->id, 'sort_order' => 1]);

        $response = $this->actingAs($user)->postJson($this->url($tenant, "lifecycle-processes/{$process->id}/tasks/reorder"), [
            'task_ids' => [$taskB->id, $taskA->id],
        ]);

        $response->assertStatus(422);
    }

    public function test_reorder_writes_audit_log(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'lifecycle.update');
        $process = LifecycleProcess::factory()->create(['tenant_id' => $tenant->id]);
        $taskA = LifecycleTask::factory()->create(['tenant_id' => $tenant->id, 'process_id' => $process->id, 'sort_order' => 0]);
        $taskB = LifecycleTask::factory()->create(['tenant_id' => $tenant->id, 'process_id' => $process->id, 'sort_order' => 1]);

        $this->actingAs($user)->postJson($this->url($tenant, "lifecycle-processes/{$process->id}/tasks/reorder"), [
            'task_ids' => [$taskB->id, $taskA->id],
        ])->assertOk();

        $this->assertDatabaseHas('audit_logs', ['action' => 'lifecycle_task.reordered', 'module' => 'lifecycle', 'actor_user_id' => $user->id]);
    }

    public function test_tenant_a_cannot_reorder_tenant_bs_process_tasks(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();
        $userA = $this->userWithPermissions($tenantA, 'lifecycle.update');
        $processB = LifecycleProcess::factory()->create(['tenant_id' => $tenantB->id]);
        $taskB1 = LifecycleTask::factory()->create(['tenant_id' => $tenantB->id, 'process_id' => $processB->id, 'sort_order' => 0]);
        $taskB2 = LifecycleTask::factory()->create(['tenant_id' => $tenantB->id, 'process_id' => $processB->id, 'sort_order' => 1]);

        $this->actingAs($userA)->postJson($this->url($tenantA, "lifecycle-processes/{$processB->id}/tasks/reorder"), [
            'task_ids' => [$taskB2->id, $taskB1->id],
        ])->assertNotFound();
    }
}
