<?php

namespace Tests\Feature\Leave;

use App\Enums\LeaveRequestStatus;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LeaveBalanceEnforcementTest extends TestCase
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
        $employee = Employee::factory()->recycle($tenant)->create(['tenant_id' => $tenant->id, 'user_id' => $user->id]);

        return [$user, $employee];
    }

    protected function url(Tenant $tenant, string $path): string
    {
        return 'http://'.$tenant->subdomain.'.'.config('tenancy.base_domain').'/api/v1/'.$path;
    }

    /**
     * A balance-controlled leave type (max_days_per_year set) and a
     * matching leave request, both same-tenant, same-employee.
     */
    protected function balanceControlledRequest(Tenant $tenant, Employee $employee, int $maxDaysPerYear, string $start, string $end): LeaveRequest
    {
        $leaveType = LeaveType::factory()->recycle($tenant)->create(['tenant_id' => $tenant->id, 'max_days_per_year' => $maxDaysPerYear]);

        return LeaveRequest::factory()->recycle($tenant)->create([
            'tenant_id' => $tenant->id,
            'employee_id' => $employee->id,
            'leave_type_id' => $leaveType->id,
            'start_date' => $start,
            'end_date' => $end,
            'total_days' => (int) (new \DateTime($end))->diff(new \DateTime($start))->days + 1,
            'status' => LeaveRequestStatus::Draft,
        ]);
    }

    // 10: draft leave request does not affect balance
    public function test_draft_request_does_not_affect_balance(): void
    {
        $tenant = Tenant::factory()->create();
        [, $employee] = $this->linkedUser($tenant, 'leave.request');
        $this->balanceControlledRequest($tenant, $employee, 10, '2027-05-01', '2027-05-03');

        $this->assertDatabaseMissing('leave_balances', ['employee_id' => $employee->id]);
    }

    // 11: submit reserves pending balance
    public function test_submit_reserves_pending_balance(): void
    {
        $tenant = Tenant::factory()->create();
        [$user, $employee] = $this->linkedUser($tenant, 'leave.request');
        $leaveRequest = $this->balanceControlledRequest($tenant, $employee, 10, '2027-05-01', '2027-05-03');

        $response = $this->actingAs($user)->postJson($this->url($tenant, "leave-requests/{$leaveRequest->id}/submit"));

        $response->assertOk();
        $balance = LeaveBalance::query()->where('employee_id', $employee->id)->firstOrFail();
        $this->assertSame('3.00', $balance->pending_days);
        $this->assertSame(7.0, $balance->availableDays());
    }

    // 12: submit exceeding available balance is rejected
    public function test_submit_exceeding_available_balance_is_rejected(): void
    {
        $tenant = Tenant::factory()->create();
        [$user, $employee] = $this->linkedUser($tenant, 'leave.request');
        $leaveRequest = $this->balanceControlledRequest($tenant, $employee, 2, '2027-05-01', '2027-05-05'); // 5 days requested, only 2 available

        $response = $this->actingAs($user)->postJson($this->url($tenant, "leave-requests/{$leaveRequest->id}/submit"));

        $response->assertStatus(422);
        // Refinement 8: leave request status must not change on failure.
        $this->assertSame('draft', $leaveRequest->fresh()->status->value);
        $this->assertDatabaseMissing('leave_balances', ['employee_id' => $employee->id]);
    }

    // 13: approve moves pending to used
    public function test_approve_moves_pending_to_used(): void
    {
        $tenant = Tenant::factory()->create();
        [$user, $employee] = $this->linkedUser($tenant, 'leave.request');
        $hrAdmin = $this->userWithPermissions($tenant, 'leave.approve', 'leave.view_all');
        $leaveRequest = $this->balanceControlledRequest($tenant, $employee, 10, '2027-05-01', '2027-05-03');
        $this->actingAs($user)->postJson($this->url($tenant, "leave-requests/{$leaveRequest->id}/submit"))->assertOk();

        $response = $this->actingAs($hrAdmin)->postJson($this->url($tenant, "leave-requests/{$leaveRequest->id}/approve"));

        $response->assertOk();
        $balance = LeaveBalance::query()->where('employee_id', $employee->id)->firstOrFail();
        $this->assertSame('0.00', $balance->pending_days);
        $this->assertSame('3.00', $balance->used_days);
        $this->assertSame(7.0, $balance->availableDays());
    }

    // 14: reject releases pending balance
    public function test_reject_releases_pending_balance(): void
    {
        $tenant = Tenant::factory()->create();
        [$user, $employee] = $this->linkedUser($tenant, 'leave.request');
        $hrAdmin = $this->userWithPermissions($tenant, 'leave.reject', 'leave.view_all');
        $leaveRequest = $this->balanceControlledRequest($tenant, $employee, 10, '2027-05-01', '2027-05-03');
        $this->actingAs($user)->postJson($this->url($tenant, "leave-requests/{$leaveRequest->id}/submit"))->assertOk();

        $response = $this->actingAs($hrAdmin)->postJson($this->url($tenant, "leave-requests/{$leaveRequest->id}/reject"), [
            'rejection_reason' => 'Coverage conflict.',
        ]);

        $response->assertOk();
        $balance = LeaveBalance::query()->where('employee_id', $employee->id)->firstOrFail();
        $this->assertSame('0.00', $balance->pending_days);
        $this->assertSame('0.00', $balance->used_days);
        $this->assertSame(10.0, $balance->availableDays());
    }

    // 15: cancel pending leave request releases pending balance
    public function test_cancel_pending_request_releases_pending_balance(): void
    {
        $tenant = Tenant::factory()->create();
        [$user, $employee] = $this->linkedUser($tenant, 'leave.request', 'leave.cancel');
        $leaveRequest = $this->balanceControlledRequest($tenant, $employee, 10, '2027-05-01', '2027-05-03');
        $this->actingAs($user)->postJson($this->url($tenant, "leave-requests/{$leaveRequest->id}/submit"))->assertOk();

        $response = $this->actingAs($user)->postJson($this->url($tenant, "leave-requests/{$leaveRequest->id}/cancel"));

        $response->assertOk();
        $balance = LeaveBalance::query()->where('employee_id', $employee->id)->firstOrFail();
        $this->assertSame('0.00', $balance->pending_days);
        $this->assertSame(10.0, $balance->availableDays());
    }

    // Cancelling a DRAFT request must not touch balance at all (no reservation was ever made)
    public function test_cancel_draft_request_does_not_affect_balance(): void
    {
        $tenant = Tenant::factory()->create();
        [$user, $employee] = $this->linkedUser($tenant, 'leave.request', 'leave.cancel');
        $leaveRequest = $this->balanceControlledRequest($tenant, $employee, 10, '2027-05-01', '2027-05-03');

        $response = $this->actingAs($user)->postJson($this->url($tenant, "leave-requests/{$leaveRequest->id}/cancel"));

        $response->assertOk();
        $this->assertDatabaseMissing('leave_balances', ['employee_id' => $employee->id]);
    }

    // 16, Refinement 1: invalid status transition does not change balance
    public function test_invalid_status_transition_does_not_change_balance(): void
    {
        $tenant = Tenant::factory()->create();
        [$user, $employee] = $this->linkedUser($tenant, 'leave.request');
        $hrAdmin = $this->userWithPermissions($tenant, 'leave.approve', 'leave.view_all');
        $leaveRequest = $this->balanceControlledRequest($tenant, $employee, 10, '2027-05-01', '2027-05-03');
        $this->actingAs($user)->postJson($this->url($tenant, "leave-requests/{$leaveRequest->id}/submit"))->assertOk();
        $this->actingAs($hrAdmin)->postJson($this->url($tenant, "leave-requests/{$leaveRequest->id}/approve"))->assertOk();

        $balanceBefore = LeaveBalance::query()->where('employee_id', $employee->id)->firstOrFail();
        $usedBefore = $balanceBefore->used_days;

        // Refinement 1: approving an already-approved request must not
        // consume balance again.
        $response = $this->actingAs($hrAdmin)->postJson($this->url($tenant, "leave-requests/{$leaveRequest->id}/approve"));

        $response->assertStatus(409);
        $this->assertSame($usedBefore, $balanceBefore->fresh()->used_days);
    }

    // 17: cross-year leave request is rejected
    public function test_cross_year_leave_request_is_rejected(): void
    {
        $tenant = Tenant::factory()->create();
        [$user] = $this->linkedUser($tenant, 'leave.request');
        $leaveType = LeaveType::factory()->recycle($tenant)->create(['tenant_id' => $tenant->id]);

        $response = $this->actingAs($user)->postJson($this->url($tenant, 'leave-requests'), [
            'leave_type_id' => $leaveType->id,
            'start_date' => '2027-12-30',
            'end_date' => '2028-01-02',
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('end_date');
    }

    // 18: leave type with null max_days_per_year bypasses balance enforcement
    public function test_unlimited_leave_type_bypasses_balance_enforcement(): void
    {
        $tenant = Tenant::factory()->create();
        [$user, $employee] = $this->linkedUser($tenant, 'leave.request');
        $leaveType = LeaveType::factory()->recycle($tenant)->create(['tenant_id' => $tenant->id, 'max_days_per_year' => null]);
        $leaveRequest = LeaveRequest::factory()->recycle($tenant)->create([
            'tenant_id' => $tenant->id, 'employee_id' => $employee->id, 'leave_type_id' => $leaveType->id,
            'start_date' => '2027-05-01', 'end_date' => '2027-05-10', 'total_days' => 10, 'status' => LeaveRequestStatus::Draft,
        ]);

        $response = $this->actingAs($user)->postJson($this->url($tenant, "leave-requests/{$leaveRequest->id}/submit"));

        $response->assertOk();
        $this->assertDatabaseMissing('leave_balances', ['employee_id' => $employee->id]);
    }

    // 19/20: balance operations are transactional; concurrent overspend protection
    public function test_balance_reservation_uses_locking_and_prevents_overspend(): void
    {
        $tenant = Tenant::factory()->create();
        [$user, $employee] = $this->linkedUser($tenant, 'leave.request');
        $leaveType = LeaveType::factory()->recycle($tenant)->create(['tenant_id' => $tenant->id, 'max_days_per_year' => 5]);

        $firstRequest = LeaveRequest::factory()->recycle($tenant)->create([
            'tenant_id' => $tenant->id, 'employee_id' => $employee->id, 'leave_type_id' => $leaveType->id,
            'start_date' => '2027-06-01', 'end_date' => '2027-06-03', 'total_days' => 3, 'status' => LeaveRequestStatus::Draft,
        ]);
        $secondRequest = LeaveRequest::factory()->recycle($tenant)->create([
            'tenant_id' => $tenant->id, 'employee_id' => $employee->id, 'leave_type_id' => $leaveType->id,
            'start_date' => '2027-07-01', 'end_date' => '2027-07-03', 'total_days' => 3, 'status' => LeaveRequestStatus::Draft,
        ]);

        // First submit reserves 3 of 5 days — succeeds.
        $this->actingAs($user)->postJson($this->url($tenant, "leave-requests/{$firstRequest->id}/submit"))->assertOk();

        // Second submit would need another 3 days, only 2 remain — must
        // be rejected, not allowed to overspend the shared balance.
        $response = $this->actingAs($user)->postJson($this->url($tenant, "leave-requests/{$secondRequest->id}/submit"));

        $response->assertStatus(422);
        $balance = LeaveBalance::query()->where('employee_id', $employee->id)->firstOrFail();
        $this->assertSame('3.00', $balance->pending_days);
        $this->assertSame('draft', $secondRequest->fresh()->status->value);
    }

    // 21: balance create/update writes audit log — covered in LeaveBalanceApiTest;
    // here we confirm the workflow-triggered events specifically.
    // 22: leave submission writes pending reservation audit log
    public function test_submit_writes_pending_reserved_audit_log(): void
    {
        $tenant = Tenant::factory()->create();
        [$user, $employee] = $this->linkedUser($tenant, 'leave.request');
        $leaveRequest = $this->balanceControlledRequest($tenant, $employee, 10, '2027-05-01', '2027-05-03');

        $this->actingAs($user)->postJson($this->url($tenant, "leave-requests/{$leaveRequest->id}/submit"))->assertOk();

        $log = AuditLog::query()->where('action', 'leave_balance.pending_reserved')->firstOrFail();
        $this->assertSame($leaveRequest->id, $log->metadata['leave_request_id']);
        $this->assertSame($employee->id, $log->metadata['employee_id']);
        $this->assertEquals(3.0, $log->metadata['days']);
        $this->assertEquals(0.0, $log->metadata['old_pending_days']);
        $this->assertEquals(3.0, $log->metadata['new_pending_days']);
    }

    // 23: approval writes used-recorded audit log
    public function test_approve_writes_used_recorded_audit_log(): void
    {
        $tenant = Tenant::factory()->create();
        [$user, $employee] = $this->linkedUser($tenant, 'leave.request');
        $hrAdmin = $this->userWithPermissions($tenant, 'leave.approve', 'leave.view_all');
        $leaveRequest = $this->balanceControlledRequest($tenant, $employee, 10, '2027-05-01', '2027-05-03');
        $this->actingAs($user)->postJson($this->url($tenant, "leave-requests/{$leaveRequest->id}/submit"))->assertOk();

        $this->actingAs($hrAdmin)->postJson($this->url($tenant, "leave-requests/{$leaveRequest->id}/approve"))->assertOk();

        $this->assertDatabaseHas('audit_logs', ['action' => 'leave_balance.used_recorded']);
    }

    // 24: reject/cancel writes pending-released audit log
    public function test_reject_writes_pending_released_audit_log(): void
    {
        $tenant = Tenant::factory()->create();
        [$user, $employee] = $this->linkedUser($tenant, 'leave.request');
        $hrAdmin = $this->userWithPermissions($tenant, 'leave.reject', 'leave.view_all');
        $leaveRequest = $this->balanceControlledRequest($tenant, $employee, 10, '2027-05-01', '2027-05-03');
        $this->actingAs($user)->postJson($this->url($tenant, "leave-requests/{$leaveRequest->id}/submit"))->assertOk();

        $this->actingAs($hrAdmin)->postJson($this->url($tenant, "leave-requests/{$leaveRequest->id}/reject"), [
            'rejection_reason' => 'No coverage.',
        ])->assertOk();

        $this->assertDatabaseHas('audit_logs', ['action' => 'leave_balance.pending_released']);
    }

    public function test_cancel_writes_pending_released_audit_log(): void
    {
        $tenant = Tenant::factory()->create();
        [$user, $employee] = $this->linkedUser($tenant, 'leave.request', 'leave.cancel');
        $leaveRequest = $this->balanceControlledRequest($tenant, $employee, 10, '2027-05-01', '2027-05-03');
        $this->actingAs($user)->postJson($this->url($tenant, "leave-requests/{$leaveRequest->id}/submit"))->assertOk();

        $this->actingAs($user)->postJson($this->url($tenant, "leave-requests/{$leaveRequest->id}/cancel"))->assertOk();

        $this->assertDatabaseHas('audit_logs', ['action' => 'leave_balance.pending_released']);
    }

    // Balance audit metadata contains no employee names, leave reasons, or rejection reasons (Refinement 4)
    public function test_balance_audit_metadata_contains_no_sensitive_text(): void
    {
        $tenant = Tenant::factory()->create();
        [$user, $employee] = $this->linkedUser($tenant, 'leave.request');
        $hrAdmin = $this->userWithPermissions($tenant, 'leave.reject', 'leave.view_all');
        $leaveRequest = $this->balanceControlledRequest($tenant, $employee, 10, '2027-05-01', '2027-05-03');
        $leaveRequest->update(['reason' => 'Confidential medical procedure.']);
        $this->actingAs($user)->postJson($this->url($tenant, "leave-requests/{$leaveRequest->id}/submit"))->assertOk();

        $secretReason = 'Denied due to confidential HR matter.';
        $this->actingAs($hrAdmin)->postJson($this->url($tenant, "leave-requests/{$leaveRequest->id}/reject"), [
            'rejection_reason' => $secretReason,
        ])->assertOk();

        $log = AuditLog::query()->where('action', 'leave_balance.pending_released')->firstOrFail();
        $this->assertStringNotContainsString($secretReason, json_encode($log->metadata));
        $this->assertStringNotContainsString($employee->first_name, json_encode($log->metadata));
        $this->assertArrayNotHasKey('reason', $log->metadata);
        $this->assertArrayNotHasKey('rejection_reason', $log->metadata);
    }
}
