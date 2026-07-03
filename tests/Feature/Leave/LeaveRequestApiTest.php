<?php

namespace Tests\Feature\Leave;

use App\Enums\LeaveRequestStatus;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class LeaveRequestApiTest extends TestCase
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
     * Creates a user linked to a fresh employee, with the given
     * permissions — the standard "self-service" fixture used across
     * most of these tests.
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

    // 4, 16: inactive leave type cannot be used for request
    public function test_inactive_leave_type_cannot_be_used_for_request(): void
    {
        $tenant = Tenant::factory()->create();
        [$user] = $this->linkedUser($tenant, 'leave.request');
        $leaveType = LeaveType::factory()->inactive()->create(['tenant_id' => $tenant->id]);

        $response = $this->actingAs($user)->postJson($this->url($tenant, 'leave-requests'), [
            'leave_type_id' => $leaveType->id,
            'start_date' => now()->addWeek()->toDateString(),
            'end_date' => now()->addWeek()->addDays(2)->toDateString(),
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('leave_type_id');
    }

    // 5: linked employee can create own leave request
    public function test_linked_employee_can_create_own_leave_request(): void
    {
        $tenant = Tenant::factory()->create();
        [$user, $employee] = $this->linkedUser($tenant, 'leave.request');
        $leaveType = LeaveType::factory()->create(['tenant_id' => $tenant->id]);

        $response = $this->actingAs($user)->postJson($this->url($tenant, 'leave-requests'), [
            'leave_type_id' => $leaveType->id,
            'start_date' => '2027-01-04',
            'end_date' => '2027-01-06',
            'reason' => 'Family trip',
        ]);

        $response->assertCreated();
        $this->assertDatabaseHas('leave_requests', [
            'tenant_id' => $tenant->id,
            'employee_id' => $employee->id,
            'total_days' => 3,
            'status' => 'draft',
        ]);
    }

    // 6: user without linked employee cannot create leave request
    public function test_user_without_linked_employee_cannot_create_leave_request(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'leave.request');
        $leaveType = LeaveType::factory()->create(['tenant_id' => $tenant->id]);

        $response = $this->actingAs($user)->postJson($this->url($tenant, 'leave-requests'), [
            'leave_type_id' => $leaveType->id,
            'start_date' => now()->addWeek()->toDateString(),
            'end_date' => now()->addWeek()->addDays(1)->toDateString(),
        ]);

        $response->assertStatus(422);
    }

    // 7: employee cannot create leave request for another employee
    // (structural: employee_id is not an accepted field at all)
    public function test_employee_id_in_request_body_cannot_bypass_self_service(): void
    {
        $tenant = Tenant::factory()->create();
        [$user, $employee] = $this->linkedUser($tenant, 'leave.request');
        $otherEmployee = Employee::factory()->create(['tenant_id' => $tenant->id]);
        $leaveType = LeaveType::factory()->create(['tenant_id' => $tenant->id]);

        $response = $this->actingAs($user)->postJson($this->url($tenant, 'leave-requests'), [
            'employee_id' => $otherEmployee->id,
            'leave_type_id' => $leaveType->id,
            'start_date' => now()->addWeek()->toDateString(),
            'end_date' => now()->addWeek()->addDays(1)->toDateString(),
        ]);

        $response->assertCreated();
        // Ignored, not honored — the request was created for the caller's
        // own employee regardless of what employee_id was submitted.
        $this->assertDatabaseHas('leave_requests', ['employee_id' => $employee->id]);
        $this->assertDatabaseMissing('leave_requests', ['employee_id' => $otherEmployee->id]);
    }

    // 25: request body tenant_id cannot force cross-tenant creation
    public function test_tenant_id_in_request_body_cannot_force_cross_tenant_creation(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();
        [$user, $employee] = $this->linkedUser($tenantA, 'leave.request');
        $leaveType = LeaveType::factory()->create(['tenant_id' => $tenantA->id]);

        $response = $this->actingAs($user)->postJson($this->url($tenantA, 'leave-requests'), [
            'tenant_id' => $tenantB->id,
            'leave_type_id' => $leaveType->id,
            'start_date' => now()->addWeek()->toDateString(),
            'end_date' => now()->addWeek()->addDays(1)->toDateString(),
        ]);

        $response->assertCreated();
        $this->assertDatabaseHas('leave_requests', ['employee_id' => $employee->id, 'tenant_id' => $tenantA->id]);
    }

    // 8: employee can view own leave requests
    public function test_employee_can_view_own_leave_requests(): void
    {
        $tenant = Tenant::factory()->create();
        [$user, $employee] = $this->linkedUser($tenant, 'leave.view');
        $leaveRequest = LeaveRequest::factory()->create(['tenant_id' => $tenant->id, 'employee_id' => $employee->id]);

        $response = $this->actingAs($user)->getJson($this->url($tenant, "leave-requests/{$leaveRequest->id}"));

        $response->assertOk();
    }

    // 9: employee cannot view another employee's leave request
    public function test_employee_cannot_view_another_employees_leave_request(): void
    {
        $tenant = Tenant::factory()->create();
        [$user] = $this->linkedUser($tenant, 'leave.view');
        $otherEmployee = Employee::factory()->create(['tenant_id' => $tenant->id]);
        $leaveRequest = LeaveRequest::factory()->create(['tenant_id' => $tenant->id, 'employee_id' => $otherEmployee->id]);

        $response = $this->actingAs($user)->getJson($this->url($tenant, "leave-requests/{$leaveRequest->id}"));

        $response->assertNotFound();
    }

    // 10: HR user with leave.view_all can view tenant leave requests
    public function test_hr_user_with_view_all_can_view_tenant_leave_requests(): void
    {
        $tenant = Tenant::factory()->create();
        $hrUser = $this->userWithPermissions($tenant, 'leave.view', 'leave.view_all');
        $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);
        $leaveRequest = LeaveRequest::factory()->create(['tenant_id' => $tenant->id, 'employee_id' => $employee->id]);

        $response = $this->actingAs($hrUser)->getJson($this->url($tenant, "leave-requests/{$leaveRequest->id}"));

        $response->assertOk();
    }

    // 11: user without leave.view_all cannot view all leave requests (list scoped to own)
    public function test_user_without_view_all_only_sees_own_leave_requests_in_list(): void
    {
        $tenant = Tenant::factory()->create();
        [$user, $employee] = $this->linkedUser($tenant, 'leave.view');
        $ownRequest = LeaveRequest::factory()->create(['tenant_id' => $tenant->id, 'employee_id' => $employee->id]);
        $otherEmployee = Employee::factory()->create(['tenant_id' => $tenant->id]);
        LeaveRequest::factory()->create(['tenant_id' => $tenant->id, 'employee_id' => $otherEmployee->id]);

        $response = $this->actingAs($user)->getJson($this->url($tenant, 'leave-requests'));

        $response->assertOk();
        $ids = collect($response->json('data'))->pluck('id')->all();
        $this->assertSame([$ownRequest->id], $ids);
    }

    // 12: employee can submit own draft request
    public function test_employee_can_submit_own_draft_request(): void
    {
        $tenant = Tenant::factory()->create();
        [$user, $employee] = $this->linkedUser($tenant, 'leave.request');
        $leaveRequest = LeaveRequest::factory()->create(['tenant_id' => $tenant->id, 'employee_id' => $employee->id, 'status' => LeaveRequestStatus::Draft]);

        $response = $this->actingAs($user)->postJson($this->url($tenant, "leave-requests/{$leaveRequest->id}/submit"));

        $response->assertOk();
        $this->assertSame('pending', $leaveRequest->fresh()->status->value);
    }

    // 13: employee can cancel own draft/pending request
    public function test_employee_can_cancel_own_pending_request(): void
    {
        $tenant = Tenant::factory()->create();
        [$user, $employee] = $this->linkedUser($tenant, 'leave.cancel');
        $leaveRequest = LeaveRequest::factory()->pending()->create(['tenant_id' => $tenant->id, 'employee_id' => $employee->id]);

        $response = $this->actingAs($user)->postJson($this->url($tenant, "leave-requests/{$leaveRequest->id}/cancel"));

        $response->assertOk();
        $leaveRequest->refresh();
        $this->assertSame('cancelled', $leaveRequest->status->value);
        $this->assertSame($user->id, $leaveRequest->cancelled_by);
    }

    // 14, 11 (security): employee cannot approve own request
    public function test_employee_cannot_approve_own_request(): void
    {
        $tenant = Tenant::factory()->create();
        [$user, $employee] = $this->linkedUser($tenant, 'leave.approve');
        $leaveRequest = LeaveRequest::factory()->pending()->create(['tenant_id' => $tenant->id, 'employee_id' => $employee->id]);

        $response = $this->actingAs($user)->postJson($this->url($tenant, "leave-requests/{$leaveRequest->id}/approve"));

        $response->assertForbidden();
        $this->assertSame('pending', $leaveRequest->fresh()->status->value);
    }

    // 15: HR user with leave.approve can approve pending request
    public function test_hr_user_with_approve_can_approve_pending_request(): void
    {
        $tenant = Tenant::factory()->create();
        $hrUser = $this->userWithPermissions($tenant, 'leave.approve');
        $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);
        $leaveRequest = LeaveRequest::factory()->pending()->create(['tenant_id' => $tenant->id, 'employee_id' => $employee->id]);

        $response = $this->actingAs($hrUser)->postJson($this->url($tenant, "leave-requests/{$leaveRequest->id}/approve"));

        $response->assertOk();
        $leaveRequest->refresh();
        $this->assertSame('approved', $leaveRequest->status->value);
        $this->assertSame($hrUser->id, $leaveRequest->approved_by);
    }

    // 16: user without leave.approve cannot approve
    public function test_user_without_approve_permission_cannot_approve(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'leave.view');
        $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);
        $leaveRequest = LeaveRequest::factory()->pending()->create(['tenant_id' => $tenant->id, 'employee_id' => $employee->id]);

        $response = $this->actingAs($user)->postJson($this->url($tenant, "leave-requests/{$leaveRequest->id}/approve"));

        $response->assertForbidden();
    }

    // 17: HR user with leave.reject can reject pending request
    public function test_hr_user_with_reject_can_reject_pending_request(): void
    {
        $tenant = Tenant::factory()->create();
        $hrUser = $this->userWithPermissions($tenant, 'leave.reject');
        $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);
        $leaveRequest = LeaveRequest::factory()->pending()->create(['tenant_id' => $tenant->id, 'employee_id' => $employee->id]);

        $response = $this->actingAs($hrUser)->postJson($this->url($tenant, "leave-requests/{$leaveRequest->id}/reject"), [
            'rejection_reason' => 'Insufficient coverage during requested period.',
        ]);

        $response->assertOk();
        $leaveRequest->refresh();
        $this->assertSame('rejected', $leaveRequest->status->value);
        $this->assertSame($hrUser->id, $leaveRequest->rejected_by);
    }

    // 18: user without leave.reject cannot reject
    public function test_user_without_reject_permission_cannot_reject(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'leave.view');
        $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);
        $leaveRequest = LeaveRequest::factory()->pending()->create(['tenant_id' => $tenant->id, 'employee_id' => $employee->id]);

        $response = $this->actingAs($user)->postJson($this->url($tenant, "leave-requests/{$leaveRequest->id}/reject"), [
            'rejection_reason' => 'No.',
        ]);

        $response->assertForbidden();
    }

    // 19: rejection requires rejection_reason
    public function test_rejection_requires_rejection_reason(): void
    {
        $tenant = Tenant::factory()->create();
        $hrUser = $this->userWithPermissions($tenant, 'leave.reject');
        $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);
        $leaveRequest = LeaveRequest::factory()->pending()->create(['tenant_id' => $tenant->id, 'employee_id' => $employee->id]);

        $response = $this->actingAs($hrUser)->postJson($this->url($tenant, "leave-requests/{$leaveRequest->id}/reject"), []);

        $response->assertStatus(422)->assertJsonValidationErrors('rejection_reason');
    }

    // 20: invalid date range is rejected
    public function test_invalid_date_range_is_rejected(): void
    {
        $tenant = Tenant::factory()->create();
        [$user] = $this->linkedUser($tenant, 'leave.request');
        $leaveType = LeaveType::factory()->create(['tenant_id' => $tenant->id]);

        $response = $this->actingAs($user)->postJson($this->url($tenant, 'leave-requests'), [
            'leave_type_id' => $leaveType->id,
            'start_date' => now()->addWeek()->toDateString(),
            'end_date' => now()->addWeek()->subDays(2)->toDateString(),
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('end_date');
    }

    // 21: total_days is calculated server-side, frontend value ignored
    public function test_total_days_is_calculated_server_side(): void
    {
        $tenant = Tenant::factory()->create();
        [$user] = $this->linkedUser($tenant, 'leave.request');
        $leaveType = LeaveType::factory()->create(['tenant_id' => $tenant->id]);

        $response = $this->actingAs($user)->postJson($this->url($tenant, 'leave-requests'), [
            'leave_type_id' => $leaveType->id,
            'start_date' => '2027-02-01',
            'end_date' => '2027-02-03',
            'total_days' => 999,
        ]);

        $response->assertCreated();
        $this->assertSame(3, $response->json('data.total_days'));
        $this->assertDatabaseHas('leave_requests', ['total_days' => 3]);
        $this->assertDatabaseMissing('leave_requests', ['total_days' => 999]);
    }

    // 22: invalid status transition is rejected
    public function test_invalid_status_transition_is_rejected(): void
    {
        $tenant = Tenant::factory()->create();
        $hrUser = $this->userWithPermissions($tenant, 'leave.approve');
        $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);
        $leaveRequest = LeaveRequest::factory()->approved()->create(['tenant_id' => $tenant->id, 'employee_id' => $employee->id]);

        $response = $this->actingAs($hrUser)->postJson($this->url($tenant, "leave-requests/{$leaveRequest->id}/approve"));

        $response->assertStatus(409);
    }

    // 23: Tenant A cannot approve Tenant B leave request
    public function test_tenant_a_cannot_approve_tenant_b_leave_request(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();
        $hrUserA = $this->userWithPermissions($tenantA, 'leave.approve');
        $employeeB = Employee::factory()->create(['tenant_id' => $tenantB->id]);
        $leaveRequestB = LeaveRequest::factory()->pending()->create(['tenant_id' => $tenantB->id, 'employee_id' => $employeeB->id]);

        $response = $this->actingAs($hrUserA)->postJson($this->url($tenantA, "leave-requests/{$leaveRequestB->id}/approve"));

        $response->assertNotFound();
    }

    // 24: Tenant A cannot reject Tenant B leave request
    public function test_tenant_a_cannot_reject_tenant_b_leave_request(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();
        $hrUserA = $this->userWithPermissions($tenantA, 'leave.reject');
        $employeeB = Employee::factory()->create(['tenant_id' => $tenantB->id]);
        $leaveRequestB = LeaveRequest::factory()->pending()->create(['tenant_id' => $tenantB->id, 'employee_id' => $employeeB->id]);

        $response = $this->actingAs($hrUserA)->postJson($this->url($tenantA, "leave-requests/{$leaveRequestB->id}/reject"), [
            'rejection_reason' => 'Cross-tenant attempt.',
        ]);

        $response->assertNotFound();
    }

    // Additional tenant-isolation coverage from the Security requirements list
    public function test_tenant_a_cannot_list_tenant_b_leave_requests(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();
        $hrUserA = $this->userWithPermissions($tenantA, 'leave.view', 'leave.view_all');
        $employeeB = Employee::factory()->create(['tenant_id' => $tenantB->id]);
        LeaveRequest::factory()->create(['tenant_id' => $tenantB->id, 'employee_id' => $employeeB->id]);

        $response = $this->actingAs($hrUserA)->getJson($this->url($tenantA, 'leave-requests'));

        $response->assertOk();
        $this->assertCount(0, $response->json('data'));
    }

    public function test_tenant_a_cannot_view_tenant_b_leave_request_by_guessed_id(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();
        $hrUserA = $this->userWithPermissions($tenantA, 'leave.view', 'leave.view_all');
        $employeeB = Employee::factory()->create(['tenant_id' => $tenantB->id]);
        $leaveRequestB = LeaveRequest::factory()->create(['tenant_id' => $tenantB->id, 'employee_id' => $employeeB->id]);

        $response = $this->actingAs($hrUserA)->getJson($this->url($tenantA, "leave-requests/{$leaveRequestB->id}"));

        $response->assertNotFound();
    }

    // 27-31: audit logs for each write action
    public function test_create_request_writes_audit_log(): void
    {
        $tenant = Tenant::factory()->create();
        [$user] = $this->linkedUser($tenant, 'leave.request');
        $leaveType = LeaveType::factory()->create(['tenant_id' => $tenant->id]);

        $this->actingAs($user)->postJson($this->url($tenant, 'leave-requests'), [
            'leave_type_id' => $leaveType->id,
            'start_date' => now()->addWeek()->toDateString(),
            'end_date' => now()->addWeek()->addDays(1)->toDateString(),
        ])->assertCreated();

        $this->assertDatabaseHas('audit_logs', ['action' => 'leave_request.created', 'module' => 'leave', 'actor_user_id' => $user->id]);
    }

    public function test_submit_request_writes_audit_log(): void
    {
        $tenant = Tenant::factory()->create();
        [$user, $employee] = $this->linkedUser($tenant, 'leave.request');
        $leaveRequest = LeaveRequest::factory()->create(['tenant_id' => $tenant->id, 'employee_id' => $employee->id]);

        $this->actingAs($user)->postJson($this->url($tenant, "leave-requests/{$leaveRequest->id}/submit"))->assertOk();

        $this->assertDatabaseHas('audit_logs', ['action' => 'leave_request.submitted', 'module' => 'leave', 'actor_user_id' => $user->id]);
    }

    public function test_approve_request_writes_audit_log(): void
    {
        $tenant = Tenant::factory()->create();
        $hrUser = $this->userWithPermissions($tenant, 'leave.approve');
        $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);
        $leaveRequest = LeaveRequest::factory()->pending()->create(['tenant_id' => $tenant->id, 'employee_id' => $employee->id]);

        $this->actingAs($hrUser)->postJson($this->url($tenant, "leave-requests/{$leaveRequest->id}/approve"))->assertOk();

        $this->assertDatabaseHas('audit_logs', ['action' => 'leave_request.approved', 'module' => 'leave', 'actor_user_id' => $hrUser->id]);
    }

    public function test_reject_request_writes_audit_log(): void
    {
        $tenant = Tenant::factory()->create();
        $hrUser = $this->userWithPermissions($tenant, 'leave.reject');
        $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);
        $leaveRequest = LeaveRequest::factory()->pending()->create(['tenant_id' => $tenant->id, 'employee_id' => $employee->id]);

        $this->actingAs($hrUser)->postJson($this->url($tenant, "leave-requests/{$leaveRequest->id}/reject"), [
            'rejection_reason' => 'Peak season, coverage conflict.',
        ])->assertOk();

        $this->assertDatabaseHas('audit_logs', ['action' => 'leave_request.rejected', 'module' => 'leave', 'actor_user_id' => $hrUser->id]);
    }

    public function test_cancel_request_writes_audit_log(): void
    {
        $tenant = Tenant::factory()->create();
        [$user, $employee] = $this->linkedUser($tenant, 'leave.cancel');
        $leaveRequest = LeaveRequest::factory()->create(['tenant_id' => $tenant->id, 'employee_id' => $employee->id]);

        $this->actingAs($user)->postJson($this->url($tenant, "leave-requests/{$leaveRequest->id}/cancel"))->assertOk();

        $this->assertDatabaseHas('audit_logs', ['action' => 'leave_request.cancelled', 'module' => 'leave', 'actor_user_id' => $user->id]);
    }

    // Refinement 7: reason/rejection_reason must not be stored raw in audit old/new values
    public function test_rejection_reason_is_not_stored_raw_in_audit_log(): void
    {
        $tenant = Tenant::factory()->create();
        $hrUser = $this->userWithPermissions($tenant, 'leave.reject');
        $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);
        $leaveRequest = LeaveRequest::factory()->pending()->create(['tenant_id' => $tenant->id, 'employee_id' => $employee->id]);
        $secretReason = 'Undergoing chemotherapy treatment.';

        $this->actingAs($hrUser)->postJson($this->url($tenant, "leave-requests/{$leaveRequest->id}/reject"), [
            'rejection_reason' => $secretReason,
        ])->assertOk();

        $auditLog = AuditLog::query()->where('action', 'leave_request.rejected')->firstOrFail();

        $this->assertSame('***MASKED***', $auditLog->new_values['rejection_reason'] ?? null);
        $this->assertStringNotContainsString($secretReason, json_encode($auditLog->new_values));
    }

    public function test_leave_reason_is_not_stored_raw_in_audit_log(): void
    {
        $tenant = Tenant::factory()->create();
        [$user] = $this->linkedUser($tenant, 'leave.request');
        $leaveType = LeaveType::factory()->create(['tenant_id' => $tenant->id]);
        $secretReason = 'Personal medical procedure, confidential.';

        $this->actingAs($user)->postJson($this->url($tenant, 'leave-requests'), [
            'leave_type_id' => $leaveType->id,
            'start_date' => now()->addWeek()->toDateString(),
            'end_date' => now()->addWeek()->addDays(1)->toDateString(),
            'reason' => $secretReason,
        ])->assertCreated();

        $auditLog = AuditLog::query()->where('action', 'leave_request.created')->firstOrFail();

        $this->assertSame('***MASKED***', $auditLog->new_values['reason'] ?? null);
        $this->assertStringNotContainsString($secretReason, json_encode($auditLog->new_values));
    }

    // 32: all leave routes include tenant.matches
    public function test_all_leave_routes_include_tenant_matches_middleware(): void
    {
        $leaveUris = [
            'api/v1/leave-types',
            'api/v1/leave-types/{leaveType}',
            'api/v1/leave-requests',
            'api/v1/leave-requests/{leaveRequest}',
            'api/v1/leave-requests/{leaveRequest}/submit',
            'api/v1/leave-requests/{leaveRequest}/approve',
            'api/v1/leave-requests/{leaveRequest}/reject',
            'api/v1/leave-requests/{leaveRequest}/cancel',
        ];

        $routes = collect(Route::getRoutes())->filter(fn ($route) => in_array($route->uri(), $leaveUris));

        $this->assertGreaterThanOrEqual(count($leaveUris), $routes->count());

        foreach ($routes as $route) {
            $this->assertContains(
                'tenant.matches',
                $route->gatherMiddleware(),
                "Route [{$route->methods()[0]} {$route->uri()}] is missing tenant.matches middleware.",
            );
        }
    }

    // 33/34: inactive user / inactive tenant fail closed
    public function test_inactive_user_fails_closed(): void
    {
        $tenant = Tenant::factory()->create();
        [$user] = $this->linkedUser($tenant, 'leave.request');
        $user->update(['status' => User::STATUS_INACTIVE]);
        $leaveType = LeaveType::factory()->create(['tenant_id' => $tenant->id]);

        $response = $this->actingAs($user)->postJson($this->url($tenant, 'leave-requests'), [
            'leave_type_id' => $leaveType->id,
            'start_date' => now()->addWeek()->toDateString(),
            'end_date' => now()->addWeek()->addDays(1)->toDateString(),
        ]);

        $response->assertForbidden();
    }

    public function test_user_under_inactive_tenant_fails_closed(): void
    {
        $tenant = Tenant::factory()->create();
        [$user] = $this->linkedUser($tenant, 'leave.request');
        $tenant->update(['status' => Tenant::STATUS_SUSPENDED]);
        $leaveType = LeaveType::factory()->create(['tenant_id' => $tenant->id]);

        $response = $this->actingAs($user)->postJson($this->url($tenant, 'leave-requests'), [
            'leave_type_id' => $leaveType->id,
            'start_date' => now()->addWeek()->toDateString(),
            'end_date' => now()->addWeek()->addDays(1)->toDateString(),
        ]);

        $response->assertForbidden();
    }

    // Refinement 2: PATCH is draft-only, owner-only, and cannot force status/employee_id/tenant_id/approval fields
    public function test_patch_cannot_edit_non_draft_request(): void
    {
        $tenant = Tenant::factory()->create();
        [$user, $employee] = $this->linkedUser($tenant, 'leave.request');
        $leaveRequest = LeaveRequest::factory()->pending()->create(['tenant_id' => $tenant->id, 'employee_id' => $employee->id]);

        $response = $this->actingAs($user)->patchJson($this->url($tenant, "leave-requests/{$leaveRequest->id}"), [
            'reason' => 'Trying to edit a pending request.',
        ]);

        $response->assertStatus(409);
    }

    public function test_patch_cannot_force_status_or_approval_fields(): void
    {
        $tenant = Tenant::factory()->create();
        [$user, $employee] = $this->linkedUser($tenant, 'leave.request');
        $leaveRequest = LeaveRequest::factory()->create(['tenant_id' => $tenant->id, 'employee_id' => $employee->id]);

        $response = $this->actingAs($user)->patchJson($this->url($tenant, "leave-requests/{$leaveRequest->id}"), [
            'status' => 'approved',
            'employee_id' => Employee::factory()->create(['tenant_id' => $tenant->id])->id,
            'tenant_id' => Tenant::factory()->create()->id,
            'approved_by' => $user->id,
            'approved_at' => now()->toDateTimeString(),
        ]);

        $response->assertOk();
        $leaveRequest->refresh();
        $this->assertSame('draft', $leaveRequest->status->value);
        $this->assertSame($employee->id, $leaveRequest->employee_id);
        $this->assertSame($tenant->id, $leaveRequest->tenant_id);
        $this->assertNull($leaveRequest->approved_by);
    }

    public function test_patch_is_owner_only(): void
    {
        $tenant = Tenant::factory()->create();
        $otherUser = $this->userWithPermissions($tenant, 'leave.request');
        $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);
        $leaveRequest = LeaveRequest::factory()->create(['tenant_id' => $tenant->id, 'employee_id' => $employee->id]);

        $response = $this->actingAs($otherUser)->patchJson($this->url($tenant, "leave-requests/{$leaveRequest->id}"), [
            'reason' => 'Not mine to edit.',
        ]);

        $response->assertForbidden();
    }
}
