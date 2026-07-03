<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\EmployeeDocument;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\Permission;
use App\Models\Policy;
use App\Models\PolicyAcknowledgement;
use App\Models\PolicyVersion;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Checkpoint 21 — GET /api/v1/dashboard. dashboard.view only grants
 * reaching this endpoint; every card is independently gated by its own
 * module permission (see DashboardController::summary()). These tests
 * verify both presence (a permission holder gets the right card, with
 * the right value/scope) and absence (a non-holder gets nothing for
 * that module) — see Refinement 9.
 */
class DashboardApiTest extends TestCase
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
        return 'http://'.$tenant->subdomain.'.'.config('tenancy.base_domain').'/api/v1/'.$path;
    }

    protected function cardsByKey(array $responseJson): array
    {
        return collect($responseJson['cards'])->keyBy('key')->all();
    }

    // 1/5: guest cannot access
    public function test_guest_cannot_access_dashboard_api(): void
    {
        $tenant = Tenant::factory()->create();

        $this->getJson($this->url($tenant, 'dashboard'))->assertUnauthorized();
    }

    // 2: dashboard.view holder can access
    public function test_user_with_dashboard_view_can_access_dashboard_api(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'dashboard.view');

        $response = $this->actingAs($user)->getJson($this->url($tenant, 'dashboard'));

        $response->assertOk();
        $response->assertJsonStructure(['cards', 'quick_links', 'recent_items']);
    }

    // 3/6: dashboard.view is required, and alone grants no module data
    public function test_user_without_dashboard_view_cannot_access_dashboard_api(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'employees.view');

        $this->actingAs($user)->getJson($this->url($tenant, 'dashboard'))->assertForbidden();
    }

    public function test_dashboard_view_alone_grants_no_module_cards(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'dashboard.view');
        Employee::factory()->recycle($tenant)->create(['tenant_id' => $tenant->id]);

        $response = $this->actingAs($user)->getJson($this->url($tenant, 'dashboard'));

        $response->assertOk();
        $this->assertSame([], $response->json('cards'));
        $this->assertSame([], $response->json('recent_items'));
    }

    // 7/10: employees.view gates the employee cards specifically
    public function test_user_without_employees_view_does_not_receive_employee_count(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'dashboard.view', 'leave.view');
        Employee::factory()->recycle($tenant)->count(3)->create(['tenant_id' => $tenant->id]);

        $response = $this->actingAs($user)->getJson($this->url($tenant, 'dashboard'));

        $cards = $this->cardsByKey($response->json());
        $this->assertArrayNotHasKey('total_employees', $cards);
        $this->assertArrayNotHasKey('active_employees', $cards);
    }

    public function test_user_with_employees_view_receives_correct_employee_counts(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'dashboard.view', 'employees.view');
        Employee::factory()->recycle($tenant)->count(2)->create(['tenant_id' => $tenant->id, 'status' => 'active']);
        Employee::factory()->recycle($tenant)->create(['tenant_id' => $tenant->id, 'status' => 'inactive']);

        $response = $this->actingAs($user)->getJson($this->url($tenant, 'dashboard'));
        $cards = $this->cardsByKey($response->json());

        $this->assertSame(3, $cards['total_employees']['value']);
        $this->assertSame(2, $cards['active_employees']['value']);
        $this->assertSame('employees.view', $cards['total_employees']['permission']);
    }

    // 11: HR/Admin receives tenant-scoped summaries
    public function test_hr_admin_receives_tenant_scoped_summary(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions(
            $tenant,
            'dashboard.view', 'employees.view', 'leave.view', 'leave.view_all',
            'policies.view', 'policies.view_acknowledgements',
        );

        $otherEmployee = Employee::factory()->recycle($tenant)->create(['tenant_id' => $tenant->id]);
        $leaveType = LeaveType::factory()->recycle($tenant)->create(['tenant_id' => $tenant->id]);
        LeaveRequest::factory()->recycle($tenant)->pending()->create([
            'tenant_id' => $tenant->id, 'employee_id' => $otherEmployee->id, 'leave_type_id' => $leaveType->id,
        ]);
        Policy::factory()->create(['tenant_id' => $tenant->id]);

        $response = $this->actingAs($user)->getJson($this->url($tenant, 'dashboard'));
        $cards = $this->cardsByKey($response->json());

        $this->assertSame('Pending Leave Requests', $cards['pending_leave']['label']);
        $this->assertSame(1, $cards['pending_leave']['value']);
        $this->assertSame(1, $cards['policies_total']['value']);
        $this->assertSame(0, $cards['policies_pending_acknowledgement']['value']);
    }

    // 12: Line Manager receives only team-scoped leave, no tenant-wide employee count
    public function test_line_manager_receives_only_team_scoped_leave_summary(): void
    {
        $tenant = Tenant::factory()->create();
        $manager = $this->userWithPermissions($tenant, 'dashboard.view', 'employees.view_team', 'leave.view', 'leave.view_team');
        $managerEmployee = Employee::factory()->recycle($tenant)->create(['tenant_id' => $tenant->id, 'user_id' => $manager->id]);

        $report = Employee::factory()->recycle($tenant)->create(['tenant_id' => $tenant->id, 'manager_employee_id' => $managerEmployee->id]);
        $unrelated = Employee::factory()->recycle($tenant)->create(['tenant_id' => $tenant->id]);
        $leaveType = LeaveType::factory()->recycle($tenant)->create(['tenant_id' => $tenant->id]);

        LeaveRequest::factory()->recycle($tenant)->pending()->create([
            'tenant_id' => $tenant->id, 'employee_id' => $report->id, 'leave_type_id' => $leaveType->id,
        ]);
        LeaveRequest::factory()->recycle($tenant)->pending()->create([
            'tenant_id' => $tenant->id, 'employee_id' => $unrelated->id, 'leave_type_id' => $leaveType->id,
        ]);

        $response = $this->actingAs($manager)->getJson($this->url($tenant, 'dashboard'));
        $cards = $this->cardsByKey($response->json());

        $this->assertArrayNotHasKey('total_employees', $cards);
        $this->assertSame(1, $cards['direct_reports']['value']);
        $this->assertSame('Pending Leave Requests (My Team)', $cards['pending_leave']['label']);
        $this->assertSame(1, $cards['pending_leave']['value']);
    }

    // 8: Employee user receives only self-service items
    public function test_employee_user_receives_only_self_service_items(): void
    {
        $tenant = Tenant::factory()->create();
        $employeeUser = $this->userWithPermissions(
            $tenant,
            'dashboard.view', 'leave.view', 'leave.request', 'leave.cancel',
            'documents.view', 'policies.view', 'policies.acknowledge',
        );
        $employee = Employee::factory()->recycle($tenant)->create(['tenant_id' => $tenant->id, 'user_id' => $employeeUser->id]);

        $otherEmployee = Employee::factory()->recycle($tenant)->create(['tenant_id' => $tenant->id]);
        $leaveType = LeaveType::factory()->recycle($tenant)->create(['tenant_id' => $tenant->id]);
        // Someone else's pending request must never affect this user's own-scoped count.
        LeaveRequest::factory()->recycle($tenant)->pending()->create([
            'tenant_id' => $tenant->id, 'employee_id' => $otherEmployee->id, 'leave_type_id' => $leaveType->id,
        ]);
        LeaveRequest::factory()->recycle($tenant)->pending()->create([
            'tenant_id' => $tenant->id, 'employee_id' => $employee->id, 'leave_type_id' => $leaveType->id,
        ]);

        $response = $this->actingAs($employeeUser)->getJson($this->url($tenant, 'dashboard'));
        $cards = $this->cardsByKey($response->json());

        $this->assertArrayNotHasKey('total_employees', $cards);
        $this->assertArrayNotHasKey('policies_pending_acknowledgement', $cards);
        $this->assertArrayNotHasKey('direct_reports', $cards);
        $this->assertSame('My Pending Leave Requests', $cards['pending_leave']['label']);
        $this->assertSame(1, $cards['pending_leave']['value']);
        $this->assertArrayHasKey('my_leave_balance', $cards);
        $this->assertArrayHasKey('my_documents_expiring_soon', $cards);
        $this->assertArrayHasKey('my_documents_recent', $cards);
    }

    public function test_user_without_linked_employee_gets_safe_empty_self_service_cards(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'dashboard.view', 'leave.view', 'documents.view', 'policies.acknowledge');

        $response = $this->actingAs($user)->getJson($this->url($tenant, 'dashboard'));
        $cards = $this->cardsByKey($response->json());

        $this->assertSame('My Pending Leave Requests', $cards['pending_leave']['label']);
        $this->assertSame(0, $cards['pending_leave']['value']);
        $this->assertArrayNotHasKey('my_leave_balance', $cards);
        $this->assertArrayNotHasKey('my_documents_expiring_soon', $cards);
        $this->assertArrayNotHasKey('my_policies_pending_acknowledgement', $cards);
    }

    // 14/15: document cards are self-scoped only, sensitive excluded unless authorized
    public function test_document_cards_are_self_scoped_and_exclude_sensitive_unless_authorized(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'dashboard.view', 'documents.view');
        $employee = Employee::factory()->recycle($tenant)->create(['tenant_id' => $tenant->id, 'user_id' => $user->id]);
        $otherEmployee = Employee::factory()->recycle($tenant)->create(['tenant_id' => $tenant->id]);

        // Own, non-sensitive, expiring soon.
        EmployeeDocument::factory()->recycle($tenant)->create([
            'tenant_id' => $tenant->id, 'employee_id' => $employee->id,
            'is_sensitive' => false, 'expiry_date' => now()->addDays(5),
        ]);
        // Own, sensitive, expiring soon — must be excluded without documents.view_sensitive.
        EmployeeDocument::factory()->recycle($tenant)->create([
            'tenant_id' => $tenant->id, 'employee_id' => $employee->id,
            'is_sensitive' => true, 'expiry_date' => now()->addDays(5),
        ]);
        // Another employee's document — must never affect this user's own-scoped count.
        EmployeeDocument::factory()->recycle($tenant)->create([
            'tenant_id' => $tenant->id, 'employee_id' => $otherEmployee->id,
            'is_sensitive' => false, 'expiry_date' => now()->addDays(5),
        ]);

        $response = $this->actingAs($user)->getJson($this->url($tenant, 'dashboard'));
        $cards = $this->cardsByKey($response->json());

        $this->assertSame(1, $cards['my_documents_expiring_soon']['value']);
    }

    public function test_document_cards_include_sensitive_documents_when_authorized(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'dashboard.view', 'documents.view', 'documents.view_sensitive');
        $employee = Employee::factory()->recycle($tenant)->create(['tenant_id' => $tenant->id, 'user_id' => $user->id]);

        EmployeeDocument::factory()->recycle($tenant)->create([
            'tenant_id' => $tenant->id, 'employee_id' => $employee->id,
            'is_sensitive' => true, 'expiry_date' => now()->addDays(5),
        ]);

        $response = $this->actingAs($user)->getJson($this->url($tenant, 'dashboard'));
        $cards = $this->cardsByKey($response->json());

        $this->assertSame(1, $cards['my_documents_expiring_soon']['value']);
    }

    // 9: tenant isolation
    public function test_tenant_a_cannot_receive_tenant_b_dashboard_data(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();
        $userA = $this->userWithPermissions($tenantA, 'dashboard.view', 'employees.view', 'policies.view');
        Employee::factory()->recycle($tenantB)->count(5)->create(['tenant_id' => $tenantB->id]);
        Policy::factory()->create(['tenant_id' => $tenantB->id]);

        $response = $this->actingAs($userA)->getJson($this->url($tenantA, 'dashboard'));
        $cards = $this->cardsByKey($response->json());

        $this->assertSame(0, $cards['total_employees']['value']);
        $this->assertSame(0, $cards['policies_total']['value']);
    }

    // 4: tenant.matches required — a tenant A session reused on tenant B's subdomain is rejected
    public function test_dashboard_api_requires_tenant_matches(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();
        $userA = $this->userWithPermissions($tenantA, 'dashboard.view');

        $response = $this->actingAs($userA)->getJson($this->url($tenantB, 'dashboard'));

        $response->assertForbidden();
    }

    // Platform admin cannot use the tenant dashboard API
    public function test_platform_super_admin_is_blocked_from_dashboard_api(): void
    {
        $admin = User::factory()->platformAdmin()->create();

        $response = $this->actingAs($admin)->getJson('http://'.config('tenancy.base_domain').'/api/v1/dashboard');

        $response->assertForbidden();
    }

    // 13/16: no sensitive or unnecessary data anywhere in the response
    public function test_dashboard_response_does_not_include_sensitive_fields(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions(
            $tenant,
            'dashboard.view', 'employees.view', 'leave.view', 'leave.view_all',
            'policies.view', 'policies.view_acknowledgements',
        );
        $employee = Employee::factory()->recycle($tenant)->create(['tenant_id' => $tenant->id]);
        $leaveType = LeaveType::factory()->recycle($tenant)->create(['tenant_id' => $tenant->id]);
        LeaveRequest::factory()->recycle($tenant)->pending()->create([
            'tenant_id' => $tenant->id, 'employee_id' => $employee->id, 'leave_type_id' => $leaveType->id,
            'reason' => 'Recovering from a confidential medical procedure.',
        ]);

        $policy = Policy::factory()->create(['tenant_id' => $tenant->id]);
        $version = PolicyVersion::factory()->create([
            'tenant_id' => $tenant->id, 'policy_id' => $policy->id, 'content' => 'Confidential internal policy text.',
        ]);
        PolicyAcknowledgement::factory()->create([
            'tenant_id' => $tenant->id, 'policy_id' => $policy->id, 'policy_version_id' => $version->id,
            'employee_id' => $employee->id, 'ip_address' => '203.0.113.5', 'user_agent' => 'Mozilla/5.0 Secret',
        ]);

        $response = $this->actingAs($user)->getJson($this->url($tenant, 'dashboard'));
        $body = json_encode($response->json());

        foreach ([
            'Recovering from a confidential medical procedure',
            'Confidential internal policy text',
            '203.0.113.5',
            'Mozilla/5.0 Secret',
            'storage_path',
            'storage_disk',
            'stored_filename',
            'rejection_reason',
        ] as $sensitiveValue) {
            $this->assertStringNotContainsString($sensitiveValue, $body, "Dashboard response unexpectedly contains '{$sensitiveValue}'.");
        }
    }
}
