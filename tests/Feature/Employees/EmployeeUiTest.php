<?php

namespace Tests\Feature\Employees;

use App\Models\Employee;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Backend-testable surface of Checkpoint 17's Employee Records UI. The
 * pages themselves fetch employee data client-side from the existing,
 * already-tested /api/v1/employees endpoints (see EmployeeApiTest) —
 * these tests cover the web route layer only: permission gating, guest
 * redirects, tenant isolation on route-model-binding, and the safe
 * employeeId prop. Button visibility, client-side 422 rendering, and
 * live API error banners are not server-testable (no JS runner
 * configured) — verified instead via tsc --noEmit, npm run build, and a
 * live HTTPS smoke test. See docs/testing.md.
 */
class EmployeeUiTest extends TestCase
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

    // 1: guest cannot access any employee UI page
    public function test_guest_cannot_access_employee_ui_pages(): void
    {
        $tenant = Tenant::factory()->create();
        $employee = Employee::factory()->recycle($tenant)->create(['tenant_id' => $tenant->id]);

        foreach (['employees', 'employees/create', "employees/{$employee->id}", "employees/{$employee->id}/edit"] as $path) {
            $this->get($this->url($tenant, $path))->assertRedirect(route('login'));
        }
    }

    // 2/3: list page permission gating
    public function test_user_without_employees_view_cannot_access_list_page(): void
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->create(['tenant_id' => $tenant->id]);

        $this->actingAs($user)->get($this->url($tenant, 'employees'))->assertForbidden();
    }

    public function test_user_with_employees_view_can_access_list_page(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'employees.view');

        $response = $this->actingAs($user)->get($this->url($tenant, 'employees'));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page->component('Employees/Index'));
    }

    // 2/3: detail page permission gating
    public function test_user_without_employees_view_cannot_access_detail_page(): void
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        $employee = Employee::factory()->recycle($tenant)->create(['tenant_id' => $tenant->id]);

        $this->actingAs($user)->get($this->url($tenant, "employees/{$employee->id}"))->assertForbidden();
    }

    public function test_user_with_employees_view_can_access_detail_page(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'employees.view');
        $employee = Employee::factory()->recycle($tenant)->create(['tenant_id' => $tenant->id]);

        $response = $this->actingAs($user)->get($this->url($tenant, "employees/{$employee->id}"));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page->component('Employees/Show')->where('employeeId', $employee->id));
    }

    // 4: create page permission gating
    public function test_user_without_employees_create_cannot_access_create_page(): void
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->create(['tenant_id' => $tenant->id]);

        $this->actingAs($user)->get($this->url($tenant, 'employees/create'))->assertForbidden();
    }

    public function test_user_with_employees_create_can_access_create_page(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'employees.create');

        $response = $this->actingAs($user)->get($this->url($tenant, 'employees/create'));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page->component('Employees/Create'));
    }

    // 5: edit page permission gating
    public function test_user_without_employees_update_cannot_access_edit_page(): void
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        $employee = Employee::factory()->recycle($tenant)->create(['tenant_id' => $tenant->id]);

        $this->actingAs($user)->get($this->url($tenant, "employees/{$employee->id}/edit"))->assertForbidden();
    }

    public function test_user_with_employees_update_can_access_edit_page(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'employees.update');
        $employee = Employee::factory()->recycle($tenant)->create(['tenant_id' => $tenant->id]);

        $response = $this->actingAs($user)->get($this->url($tenant, "employees/{$employee->id}/edit"));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page->component('Employees/Edit')->where('employeeId', $employee->id));
    }

    // 6: cross-tenant employee ID returns safe 404 for show/edit
    public function test_show_page_returns_404_for_cross_tenant_employee(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();
        $userA = $this->userWithPermissions($tenantA, 'employees.view');
        $employeeB = Employee::factory()->recycle($tenantB)->create(['tenant_id' => $tenantB->id]);

        $response = $this->actingAs($userA)->get($this->url($tenantA, "employees/{$employeeB->id}"));

        $response->assertNotFound();
    }

    public function test_edit_page_returns_404_for_cross_tenant_employee(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();
        $userA = $this->userWithPermissions($tenantA, 'employees.update');
        $employeeB = Employee::factory()->recycle($tenantB)->create(['tenant_id' => $tenantB->id]);

        $response = $this->actingAs($userA)->get($this->url($tenantA, "employees/{$employeeB->id}/edit"));

        $response->assertNotFound();
    }

    /**
     * Confirms the web route never embeds employee data in shared
     * Inertia props — only the ID. Sensitive-field masking itself is
     * already fully covered by EmployeeApiTest (Checkpoints 6/7), since
     * that's where the actual data the frontend will render comes from.
     */
    public function test_show_page_props_contain_only_employee_id_not_employee_data(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'employees.view');
        $employee = Employee::factory()->recycle($tenant)->create([
            'tenant_id' => $tenant->id,
            'personal_email' => 'secret@example.com',
        ]);

        $response = $this->actingAs($user)->get($this->url($tenant, "employees/{$employee->id}"));

        $page = $response->viewData('page');
        $this->assertSame(['employeeId'], array_keys(array_diff_key($page['props'], ['errors' => 1, 'auth' => 1, 'tenant' => 1])));
        $this->assertStringNotContainsString('secret@example.com', json_encode($page['props']));
    }
}
