<?php

namespace Tests\Feature\Leave;

use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class ManagerScopedLeaveApprovalTest extends TestCase
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

    /**
     * A user linked to a fresh employee, with the given permissions.
     */
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

    // 1: Line Manager role has leave.approve and leave.reject after scoped enforcement
    public function test_line_manager_role_has_approve_and_reject_permissions(): void
    {
        $tenant = Tenant::factory()->create();
        $lineManagerRole = Role::factory()->create(['tenant_id' => $tenant->id, 'slug' => 'line-manager']);
        foreach (['leave.approve', 'leave.reject'] as $key) {
            $permission = Permission::query()->firstOrCreate(['key' => $key], ['category' => 'leave', 'is_platform_permission' => false]);
            $lineManagerRole->givePermissionTo($permission);
        }
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        $user->assignRole($lineManagerRole);

        $this->assertTrue($user->hasPermission('leave.approve'));
        $this->assertTrue($user->hasPermission('leave.reject'));
    }

    // 2: Line Manager does not have leave.view_all
    public function test_line_manager_does_not_have_view_all(): void
    {
        $tenant = Tenant::factory()->create();
        [$lineManager] = $this->linkedUser($tenant, 'leave.view', 'leave.view_team', 'leave.approve', 'leave.reject');

        $this->assertFalse($lineManager->hasPermission('leave.view_all'));
    }

    // 3: Line Manager has leave.view_team
    public function test_line_manager_has_view_team(): void
    {
        $tenant = Tenant::factory()->create();
        [$lineManager] = $this->linkedUser($tenant, 'leave.view_team');

        $this->assertTrue($lineManager->hasPermission('leave.view_team'));
    }

    // 4: Line Manager can list direct reports' leave requests (+ own)
    public function test_line_manager_can_list_direct_reports_leave_requests(): void
    {
        $tenant = Tenant::factory()->create();
        [$manager, $managerEmployee] = $this->linkedUser($tenant, 'leave.view', 'leave.view_team');
        $report = Employee::factory()->create(['tenant_id' => $tenant->id, 'manager_employee_id' => $managerEmployee->id]);
        $reportRequest = LeaveRequest::factory()->create(['tenant_id' => $tenant->id, 'employee_id' => $report->id]);
        $unrelated = Employee::factory()->create(['tenant_id' => $tenant->id]);
        LeaveRequest::factory()->create(['tenant_id' => $tenant->id, 'employee_id' => $unrelated->id]);

        $response = $this->actingAs($manager)->getJson($this->url($tenant, 'leave-requests'));

        $response->assertOk();
        $ids = collect($response->json('data'))->pluck('id')->all();
        $this->assertContains($reportRequest->id, $ids);
        $this->assertCount(1, $ids);
    }

    // 5: Line Manager cannot list unrelated employees' leave requests
    public function test_line_manager_cannot_list_unrelated_employees_leave_requests(): void
    {
        $tenant = Tenant::factory()->create();
        [$manager] = $this->linkedUser($tenant, 'leave.view', 'leave.view_team');
        $unrelated = Employee::factory()->create(['tenant_id' => $tenant->id]);
        LeaveRequest::factory()->create(['tenant_id' => $tenant->id, 'employee_id' => $unrelated->id]);

        $response = $this->actingAs($manager)->getJson($this->url($tenant, 'leave-requests'));

        $response->assertOk();
        $this->assertCount(0, $response->json('data'));
    }

    // 6: Line Manager can view direct report leave request
    public function test_line_manager_can_view_direct_report_leave_request(): void
    {
        $tenant = Tenant::factory()->create();
        [$manager, $managerEmployee] = $this->linkedUser($tenant, 'leave.view', 'leave.view_team');
        $report = Employee::factory()->create(['tenant_id' => $tenant->id, 'manager_employee_id' => $managerEmployee->id]);
        $leaveRequest = LeaveRequest::factory()->create(['tenant_id' => $tenant->id, 'employee_id' => $report->id]);

        $response = $this->actingAs($manager)->getJson($this->url($tenant, "leave-requests/{$leaveRequest->id}"));

        $response->assertOk();
    }

    // 7: Line Manager cannot view unrelated employee leave request
    public function test_line_manager_cannot_view_unrelated_employee_leave_request(): void
    {
        $tenant = Tenant::factory()->create();
        [$manager] = $this->linkedUser($tenant, 'leave.view', 'leave.view_team');
        $unrelated = Employee::factory()->create(['tenant_id' => $tenant->id]);
        $leaveRequest = LeaveRequest::factory()->create(['tenant_id' => $tenant->id, 'employee_id' => $unrelated->id]);

        $response = $this->actingAs($manager)->getJson($this->url($tenant, "leave-requests/{$leaveRequest->id}"));

        $response->assertNotFound();
    }

    // 8: Line Manager can approve direct report pending leave request
    public function test_line_manager_can_approve_direct_report_pending_request(): void
    {
        $tenant = Tenant::factory()->create();
        [$manager, $managerEmployee] = $this->linkedUser($tenant, 'leave.approve');
        $report = Employee::factory()->create(['tenant_id' => $tenant->id, 'manager_employee_id' => $managerEmployee->id]);
        $leaveRequest = LeaveRequest::factory()->pending()->create(['tenant_id' => $tenant->id, 'employee_id' => $report->id]);

        $response = $this->actingAs($manager)->postJson($this->url($tenant, "leave-requests/{$leaveRequest->id}/approve"));

        $response->assertOk();
        $this->assertSame('approved', $leaveRequest->fresh()->status->value);
    }

    // 9: Line Manager can reject direct report pending leave request
    public function test_line_manager_can_reject_direct_report_pending_request(): void
    {
        $tenant = Tenant::factory()->create();
        [$manager, $managerEmployee] = $this->linkedUser($tenant, 'leave.reject');
        $report = Employee::factory()->create(['tenant_id' => $tenant->id, 'manager_employee_id' => $managerEmployee->id]);
        $leaveRequest = LeaveRequest::factory()->pending()->create(['tenant_id' => $tenant->id, 'employee_id' => $report->id]);

        $response = $this->actingAs($manager)->postJson($this->url($tenant, "leave-requests/{$leaveRequest->id}/reject"), [
            'rejection_reason' => 'Team is short-staffed that week.',
        ]);

        $response->assertOk();
        $this->assertSame('rejected', $leaveRequest->fresh()->status->value);
    }

    // 10: Line Manager cannot approve unrelated employee's leave request
    public function test_line_manager_cannot_approve_unrelated_employees_request(): void
    {
        $tenant = Tenant::factory()->create();
        [$manager] = $this->linkedUser($tenant, 'leave.approve');
        $unrelated = Employee::factory()->create(['tenant_id' => $tenant->id]);
        $leaveRequest = LeaveRequest::factory()->pending()->create(['tenant_id' => $tenant->id, 'employee_id' => $unrelated->id]);

        $response = $this->actingAs($manager)->postJson($this->url($tenant, "leave-requests/{$leaveRequest->id}/approve"));

        $response->assertForbidden();
        $this->assertSame('pending', $leaveRequest->fresh()->status->value);
    }

    // 11: Line Manager cannot reject unrelated employee's leave request
    public function test_line_manager_cannot_reject_unrelated_employees_request(): void
    {
        $tenant = Tenant::factory()->create();
        [$manager] = $this->linkedUser($tenant, 'leave.reject');
        $unrelated = Employee::factory()->create(['tenant_id' => $tenant->id]);
        $leaveRequest = LeaveRequest::factory()->pending()->create(['tenant_id' => $tenant->id, 'employee_id' => $unrelated->id]);

        $response = $this->actingAs($manager)->postJson($this->url($tenant, "leave-requests/{$leaveRequest->id}/reject"), [
            'rejection_reason' => 'Not my report.',
        ]);

        $response->assertForbidden();
        $this->assertSame('pending', $leaveRequest->fresh()->status->value);
    }

    // 12: Line Manager cannot approve own leave request
    public function test_line_manager_cannot_approve_own_request(): void
    {
        $tenant = Tenant::factory()->create();
        [$manager, $managerEmployee] = $this->linkedUser($tenant, 'leave.approve');
        $leaveRequest = LeaveRequest::factory()->pending()->create(['tenant_id' => $tenant->id, 'employee_id' => $managerEmployee->id]);

        $response = $this->actingAs($manager)->postJson($this->url($tenant, "leave-requests/{$leaveRequest->id}/approve"));

        $response->assertForbidden();
    }

    // 13: Line Manager cannot reject own leave request
    public function test_line_manager_cannot_reject_own_request(): void
    {
        $tenant = Tenant::factory()->create();
        [$manager, $managerEmployee] = $this->linkedUser($tenant, 'leave.reject');
        $leaveRequest = LeaveRequest::factory()->pending()->create(['tenant_id' => $tenant->id, 'employee_id' => $managerEmployee->id]);

        $response = $this->actingAs($manager)->postJson($this->url($tenant, "leave-requests/{$leaveRequest->id}/reject"), [
            'rejection_reason' => 'N/A',
        ]);

        $response->assertForbidden();
    }

    // 14: Line Manager cannot approve indirect report (direct-only chosen)
    public function test_line_manager_cannot_approve_indirect_report(): void
    {
        $tenant = Tenant::factory()->create();
        [$topManager, $topEmployee] = $this->linkedUser($tenant, 'leave.approve');
        $midManager = Employee::factory()->create(['tenant_id' => $tenant->id, 'manager_employee_id' => $topEmployee->id]);
        $grandchild = Employee::factory()->create(['tenant_id' => $tenant->id, 'manager_employee_id' => $midManager->id]);
        $leaveRequest = LeaveRequest::factory()->pending()->create(['tenant_id' => $tenant->id, 'employee_id' => $grandchild->id]);

        $response = $this->actingAs($topManager)->postJson($this->url($tenant, "leave-requests/{$leaveRequest->id}/approve"));

        $response->assertForbidden();
        $this->assertSame('pending', $leaveRequest->fresh()->status->value);
    }

    // 15: Line Manager cannot reject indirect report (direct-only chosen)
    public function test_line_manager_cannot_reject_indirect_report(): void
    {
        $tenant = Tenant::factory()->create();
        [$topManager, $topEmployee] = $this->linkedUser($tenant, 'leave.reject');
        $midManager = Employee::factory()->create(['tenant_id' => $tenant->id, 'manager_employee_id' => $topEmployee->id]);
        $grandchild = Employee::factory()->create(['tenant_id' => $tenant->id, 'manager_employee_id' => $midManager->id]);
        $leaveRequest = LeaveRequest::factory()->pending()->create(['tenant_id' => $tenant->id, 'employee_id' => $grandchild->id]);

        $response = $this->actingAs($topManager)->postJson($this->url($tenant, "leave-requests/{$leaveRequest->id}/reject"), [
            'rejection_reason' => 'Not a direct report.',
        ]);

        $response->assertForbidden();
        $this->assertSame('pending', $leaveRequest->fresh()->status->value);
    }

    // 16: HR/Tenant Admin can still approve tenant leave request
    public function test_hr_admin_can_still_approve_tenant_leave_request(): void
    {
        $tenant = Tenant::factory()->create();
        $hrAdmin = $this->userWithPermissions($tenant, 'leave.approve', 'leave.view_all');
        $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);
        $leaveRequest = LeaveRequest::factory()->pending()->create(['tenant_id' => $tenant->id, 'employee_id' => $employee->id]);

        $response = $this->actingAs($hrAdmin)->postJson($this->url($tenant, "leave-requests/{$leaveRequest->id}/approve"));

        $response->assertOk();
    }

    // 17: HR/Tenant Admin cannot approve own leave request
    public function test_hr_admin_cannot_approve_own_leave_request(): void
    {
        $tenant = Tenant::factory()->create();
        [$hrAdmin, $employee] = $this->linkedUser($tenant, 'leave.approve', 'leave.view_all');
        $leaveRequest = LeaveRequest::factory()->pending()->create(['tenant_id' => $tenant->id, 'employee_id' => $employee->id]);

        $response = $this->actingAs($hrAdmin)->postJson($this->url($tenant, "leave-requests/{$leaveRequest->id}/approve"));

        $response->assertForbidden();
    }

    // 18: HR/Tenant Admin can still reject tenant leave request
    public function test_hr_admin_can_still_reject_tenant_leave_request(): void
    {
        $tenant = Tenant::factory()->create();
        $hrAdmin = $this->userWithPermissions($tenant, 'leave.reject', 'leave.view_all');
        $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);
        $leaveRequest = LeaveRequest::factory()->pending()->create(['tenant_id' => $tenant->id, 'employee_id' => $employee->id]);

        $response = $this->actingAs($hrAdmin)->postJson($this->url($tenant, "leave-requests/{$leaveRequest->id}/reject"), [
            'rejection_reason' => 'Policy conflict.',
        ]);

        $response->assertOk();
    }

    // 19: HR/Tenant Admin cannot reject own leave request
    public function test_hr_admin_cannot_reject_own_leave_request(): void
    {
        $tenant = Tenant::factory()->create();
        [$hrAdmin, $employee] = $this->linkedUser($tenant, 'leave.reject', 'leave.view_all');
        $leaveRequest = LeaveRequest::factory()->pending()->create(['tenant_id' => $tenant->id, 'employee_id' => $employee->id]);

        $response = $this->actingAs($hrAdmin)->postJson($this->url($tenant, "leave-requests/{$leaveRequest->id}/reject"), [
            'rejection_reason' => 'N/A',
        ]);

        $response->assertForbidden();
    }

    /**
     * Refinement 6 — the most important regression test this checkpoint:
     * proves the OLD (Checkpoint 12) unsafe behavior — leave.approve
     * alone being sufficient tenant-wide — is now closed.
     */
    public function test_user_with_approve_but_no_hr_scope_and_no_manager_relationship_cannot_approve(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'leave.approve');
        $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);
        $leaveRequest = LeaveRequest::factory()->pending()->create(['tenant_id' => $tenant->id, 'employee_id' => $employee->id]);

        $response = $this->actingAs($user)->postJson($this->url($tenant, "leave-requests/{$leaveRequest->id}/approve"));

        $response->assertForbidden();
        $this->assertSame('pending', $leaveRequest->fresh()->status->value);
    }

    /**
     * Refinement 6 — the equivalent regression test for reject.
     */
    public function test_user_with_reject_but_no_hr_scope_and_no_manager_relationship_cannot_reject(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'leave.reject');
        $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);
        $leaveRequest = LeaveRequest::factory()->pending()->create(['tenant_id' => $tenant->id, 'employee_id' => $employee->id]);

        $response = $this->actingAs($user)->postJson($this->url($tenant, "leave-requests/{$leaveRequest->id}/reject"), [
            'rejection_reason' => 'Trying anyway.',
        ]);

        $response->assertForbidden();
        $this->assertSame('pending', $leaveRequest->fresh()->status->value);
    }

    // 22: user without linked employee cannot manager-approve
    public function test_user_without_linked_employee_cannot_manager_approve(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'leave.approve');
        $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);
        $leaveRequest = LeaveRequest::factory()->pending()->create(['tenant_id' => $tenant->id, 'employee_id' => $employee->id]);

        $response = $this->actingAs($user)->postJson($this->url($tenant, "leave-requests/{$leaveRequest->id}/approve"));

        $response->assertForbidden();
    }

    // 23: Tenant A manager cannot approve Tenant B leave request
    public function test_tenant_a_manager_cannot_approve_tenant_b_leave_request(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();
        [$managerA] = $this->linkedUser($tenantA, 'leave.approve');
        $employeeB = Employee::factory()->create(['tenant_id' => $tenantB->id]);
        $leaveRequestB = LeaveRequest::factory()->pending()->create(['tenant_id' => $tenantB->id, 'employee_id' => $employeeB->id]);

        $response = $this->actingAs($managerA)->postJson($this->url($tenantA, "leave-requests/{$leaveRequestB->id}/approve"));

        $response->assertNotFound();
    }

    // 24: approval audit log includes approval_scope (direct_manager case)
    public function test_approval_audit_log_includes_approval_scope(): void
    {
        $tenant = Tenant::factory()->create();
        [$manager, $managerEmployee] = $this->linkedUser($tenant, 'leave.approve');
        $report = Employee::factory()->create(['tenant_id' => $tenant->id, 'manager_employee_id' => $managerEmployee->id]);
        $leaveRequest = LeaveRequest::factory()->pending()->create(['tenant_id' => $tenant->id, 'employee_id' => $report->id]);

        $this->actingAs($manager)->postJson($this->url($tenant, "leave-requests/{$leaveRequest->id}/approve"))->assertOk();

        $auditLog = AuditLog::query()->where('action', 'leave_request.approved')->firstOrFail();
        $this->assertSame('direct_manager', $auditLog->metadata['approval_scope']);
        $this->assertSame($managerEmployee->id, $auditLog->metadata['actor_employee_id']);
        $this->assertSame($manager->id, $auditLog->metadata['actor_user_id']);
        $this->assertSame('pending', $auditLog->metadata['old_status']);
        $this->assertSame('approved', $auditLog->metadata['new_status']);
        $this->assertSame($leaveRequest->id, $auditLog->metadata['leave_request_id']);
    }

    // 25: rejection audit log includes approval_scope (hr_admin case)
    public function test_rejection_audit_log_includes_approval_scope(): void
    {
        $tenant = Tenant::factory()->create();
        $hrAdmin = $this->userWithPermissions($tenant, 'leave.reject', 'leave.view_all');
        $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);
        $leaveRequest = LeaveRequest::factory()->pending()->create(['tenant_id' => $tenant->id, 'employee_id' => $employee->id]);

        $this->actingAs($hrAdmin)->postJson($this->url($tenant, "leave-requests/{$leaveRequest->id}/reject"), [
            'rejection_reason' => 'Blackout period.',
        ])->assertOk();

        $auditLog = AuditLog::query()->where('action', 'leave_request.rejected')->firstOrFail();
        $this->assertSame('hr_admin', $auditLog->metadata['approval_scope']);
        $this->assertSame('pending', $auditLog->metadata['old_status']);
        $this->assertSame('rejected', $auditLog->metadata['new_status']);
    }

    // 26: rejection audit log does not expose raw rejection reason
    public function test_rejection_audit_log_does_not_expose_raw_reason(): void
    {
        $tenant = Tenant::factory()->create();
        $hrAdmin = $this->userWithPermissions($tenant, 'leave.reject', 'leave.view_all');
        $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);
        $leaveRequest = LeaveRequest::factory()->pending()->create(['tenant_id' => $tenant->id, 'employee_id' => $employee->id]);
        $secretReason = 'Recovering from surgery.';

        $this->actingAs($hrAdmin)->postJson($this->url($tenant, "leave-requests/{$leaveRequest->id}/reject"), [
            'rejection_reason' => $secretReason,
        ])->assertOk();

        $auditLog = AuditLog::query()->where('action', 'leave_request.rejected')->firstOrFail();

        $this->assertSame('***MASKED***', $auditLog->new_values['rejection_reason'] ?? null);
        $this->assertStringNotContainsString($secretReason, json_encode($auditLog->new_values));
        $this->assertStringNotContainsString($secretReason, json_encode($auditLog->metadata));
    }

    // 27: full /api/v1 route audit still passes
    public function test_full_api_route_audit_still_passes(): void
    {
        $routes = collect(Route::getRoutes())->filter(fn ($route) => str_starts_with($route->uri(), 'api/v1/'));

        $this->assertGreaterThan(30, $routes->count());

        foreach ($routes as $route) {
            $this->assertContains(
                'tenant.matches',
                $route->gatherMiddleware(),
                "Route [{$route->methods()[0]} {$route->uri()}] is missing tenant.matches middleware.",
            );
        }
    }

    // Inactive user / inactive tenant fail closed (manager-scoped path)
    public function test_inactive_manager_fails_closed(): void
    {
        $tenant = Tenant::factory()->create();
        [$manager, $managerEmployee] = $this->linkedUser($tenant, 'leave.approve');
        $manager->update(['status' => User::STATUS_INACTIVE]);
        $report = Employee::factory()->create(['tenant_id' => $tenant->id, 'manager_employee_id' => $managerEmployee->id]);
        $leaveRequest = LeaveRequest::factory()->pending()->create(['tenant_id' => $tenant->id, 'employee_id' => $report->id]);

        $response = $this->actingAs($manager)->postJson($this->url($tenant, "leave-requests/{$leaveRequest->id}/approve"));

        $response->assertForbidden();
    }

    public function test_manager_under_inactive_tenant_fails_closed(): void
    {
        $tenant = Tenant::factory()->create();
        [$manager, $managerEmployee] = $this->linkedUser($tenant, 'leave.approve');
        $report = Employee::factory()->create(['tenant_id' => $tenant->id, 'manager_employee_id' => $managerEmployee->id]);
        $leaveRequest = LeaveRequest::factory()->pending()->create(['tenant_id' => $tenant->id, 'employee_id' => $report->id]);
        $tenant->update(['status' => Tenant::STATUS_SUSPENDED]);

        $response = $this->actingAs($manager)->postJson($this->url($tenant, "leave-requests/{$leaveRequest->id}/approve"));

        $response->assertForbidden();
    }
}
