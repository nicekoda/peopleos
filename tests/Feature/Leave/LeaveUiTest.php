<?php

namespace Tests\Feature\Leave;

use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Backend-testable surface of Checkpoint 18's Leave Management UI —
 * same shape as EmployeeUiTest (Checkpoint 17). Leave request/type/
 * balance data is fetched client-side from the existing, already-tested
 * /api/v1 endpoints (see LeaveRequestApiTest, LeaveBalanceApiTest,
 * ManagerScopedLeaveApprovalTest) — these tests cover only the web
 * route layer: permission gating, guest redirects, tenant isolation on
 * route-model-binding, and the safe leaveRequestId prop. Button
 * visibility, the reject-reason prompt, and client-side 403/409/422
 * banners are not server-testable — verified via tsc --noEmit,
 * npm run build, and a live HTTPS smoke test. See docs/testing.md.
 */
class LeaveUiTest extends TestCase
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

    protected function url(Tenant $tenant, string $path): string
    {
        return 'http://'.$tenant->subdomain.'.'.config('tenancy.base_domain').'/'.$path;
    }

    // 1: guest cannot access any leave UI page
    public function test_guest_cannot_access_leave_ui_pages(): void
    {
        $tenant = Tenant::factory()->create();
        $employee = Employee::factory()->recycle($tenant)->create(['tenant_id' => $tenant->id]);
        $leaveRequest = LeaveRequest::factory()->recycle($tenant)->create(['tenant_id' => $tenant->id, 'employee_id' => $employee->id]);

        foreach (['leave', 'leave/create', "leave/{$leaveRequest->id}"] as $path) {
            $this->get($this->url($tenant, $path))->assertRedirect(route('login'));
        }
    }

    // 2/3: list page permission gating
    public function test_user_without_leave_view_cannot_access_list_page(): void
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->create(['tenant_id' => $tenant->id]);

        $this->actingAs($user)->get($this->url($tenant, 'leave'))->assertForbidden();
    }

    public function test_user_with_leave_view_can_access_list_page(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'leave.view');

        $response = $this->actingAs($user)->get($this->url($tenant, 'leave'));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page->component('Leave/Index'));
    }

    // 2/3: detail page permission gating
    public function test_user_without_leave_view_cannot_access_detail_page(): void
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        $employee = Employee::factory()->recycle($tenant)->create(['tenant_id' => $tenant->id]);
        $leaveRequest = LeaveRequest::factory()->recycle($tenant)->create(['tenant_id' => $tenant->id, 'employee_id' => $employee->id]);

        $this->actingAs($user)->get($this->url($tenant, "leave/{$leaveRequest->id}"))->assertForbidden();
    }

    public function test_user_with_leave_view_can_access_detail_page(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'leave.view');
        $employee = Employee::factory()->recycle($tenant)->create(['tenant_id' => $tenant->id]);
        $leaveRequest = LeaveRequest::factory()->recycle($tenant)->create(['tenant_id' => $tenant->id, 'employee_id' => $employee->id]);

        $response = $this->actingAs($user)->get($this->url($tenant, "leave/{$leaveRequest->id}"));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page->component('Leave/Show')->where('leaveRequestId', $leaveRequest->id));
    }

    // 4: create page permission gating
    public function test_user_without_leave_request_cannot_access_create_page(): void
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->create(['tenant_id' => $tenant->id]);

        $this->actingAs($user)->get($this->url($tenant, 'leave/create'))->assertForbidden();
    }

    // 5: create page permission gating (allowed)
    public function test_user_with_leave_request_can_access_create_page(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'leave.request');

        $response = $this->actingAs($user)->get($this->url($tenant, 'leave/create'));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page->component('Leave/Create'));
    }

    // 6: cross-tenant leave request ID returns safe 404 for detail
    public function test_show_page_returns_404_for_cross_tenant_leave_request(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();
        $userA = $this->userWithPermissions($tenantA, 'leave.view');
        $employeeB = Employee::factory()->recycle($tenantB)->create(['tenant_id' => $tenantB->id]);
        $leaveRequestB = LeaveRequest::factory()->recycle($tenantB)->create(['tenant_id' => $tenantB->id, 'employee_id' => $employeeB->id]);

        $response = $this->actingAs($userA)->get($this->url($tenantA, "leave/{$leaveRequestB->id}"));

        $response->assertNotFound();
    }

    // 7: shared Inertia props for the leave pages carry only the ID, never leave request data
    public function test_show_page_props_contain_only_leave_request_id_not_leave_data(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'leave.view');
        $employee = Employee::factory()->recycle($tenant)->create(['tenant_id' => $tenant->id]);
        $leaveRequest = LeaveRequest::factory()->recycle($tenant)->create([
            'tenant_id' => $tenant->id,
            'employee_id' => $employee->id,
            'reason' => 'Confidential medical procedure.',
        ]);

        $response = $this->actingAs($user)->get($this->url($tenant, "leave/{$leaveRequest->id}"));

        $page = $response->viewData('page');
        $this->assertSame(['leaveRequestId'], array_keys(array_diff_key($page['props'], ['errors' => 1, 'auth' => 1, 'tenant' => 1])));
        $this->assertStringNotContainsString('Confidential medical procedure.', json_encode($page['props']));
    }
}
