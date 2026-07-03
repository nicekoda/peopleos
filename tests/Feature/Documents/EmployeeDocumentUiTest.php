<?php

namespace Tests\Feature\Documents;

use App\Models\Employee;
use App\Models\EmployeeDocument;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Backend-testable surface of Checkpoint 19's Document Repository UI —
 * same shape as EmployeeUiTest/LeaveUiTest. Document data is fetched
 * client-side from the existing, already-tested /api/v1/employees/
 * {employee}/documents endpoints (Checkpoint 8) — these tests cover only
 * the web route layer: permission gating, guest redirects, tenant
 * isolation, the same-tenant-wrong-employee ownership check, and the
 * safe employeeId/documentId props. Upload form behaviour, the download
 * helper, delete confirmation, and client-side error banners are not
 * server-testable — verified via tsc --noEmit, npm run build, and a live
 * HTTPS smoke test. See docs/testing.md.
 */
class EmployeeDocumentUiTest extends TestCase
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

    // 1: guest cannot access any document UI page
    public function test_guest_cannot_access_document_ui_pages(): void
    {
        $tenant = Tenant::factory()->create();
        $employee = Employee::factory()->recycle($tenant)->create(['tenant_id' => $tenant->id]);
        $document = EmployeeDocument::factory()->recycle($tenant)->create(['tenant_id' => $tenant->id, 'employee_id' => $employee->id]);

        foreach ([
            "employees/{$employee->id}/documents",
            "employees/{$employee->id}/documents/upload",
            "employees/{$employee->id}/documents/{$document->id}",
        ] as $path) {
            $this->get($this->url($tenant, $path))->assertRedirect(route('login'));
        }
    }

    // 2/3: list page permission gating
    public function test_user_without_documents_view_cannot_access_list_page(): void
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        $employee = Employee::factory()->recycle($tenant)->create(['tenant_id' => $tenant->id]);

        $this->actingAs($user)->get($this->url($tenant, "employees/{$employee->id}/documents"))->assertForbidden();
    }

    public function test_user_with_documents_view_can_access_list_page(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'documents.view');
        $employee = Employee::factory()->recycle($tenant)->create(['tenant_id' => $tenant->id]);

        $response = $this->actingAs($user)->get($this->url($tenant, "employees/{$employee->id}/documents"));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page->component('Employees/Documents/Index')->where('employeeId', $employee->id));
    }

    // 2/3: detail page permission gating
    public function test_user_without_documents_view_cannot_access_detail_page(): void
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        $employee = Employee::factory()->recycle($tenant)->create(['tenant_id' => $tenant->id]);
        $document = EmployeeDocument::factory()->recycle($tenant)->create(['tenant_id' => $tenant->id, 'employee_id' => $employee->id]);

        $this->actingAs($user)->get($this->url($tenant, "employees/{$employee->id}/documents/{$document->id}"))->assertForbidden();
    }

    public function test_user_with_documents_view_can_access_detail_page(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'documents.view');
        $employee = Employee::factory()->recycle($tenant)->create(['tenant_id' => $tenant->id]);
        $document = EmployeeDocument::factory()->recycle($tenant)->create(['tenant_id' => $tenant->id, 'employee_id' => $employee->id]);

        $response = $this->actingAs($user)->get($this->url($tenant, "employees/{$employee->id}/documents/{$document->id}"));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page->component('Employees/Documents/Show')
            ->where('employeeId', $employee->id)
            ->where('documentId', $document->id));
    }

    // 4: upload page permission gating
    public function test_user_without_documents_upload_cannot_access_upload_page(): void
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        $employee = Employee::factory()->recycle($tenant)->create(['tenant_id' => $tenant->id]);

        $this->actingAs($user)->get($this->url($tenant, "employees/{$employee->id}/documents/upload"))->assertForbidden();
    }

    // 5: upload page permission gating (allowed)
    public function test_user_with_documents_upload_can_access_upload_page(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'documents.upload');
        $employee = Employee::factory()->recycle($tenant)->create(['tenant_id' => $tenant->id]);

        $response = $this->actingAs($user)->get($this->url($tenant, "employees/{$employee->id}/documents/upload"));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page->component('Employees/Documents/Upload')->where('employeeId', $employee->id));
    }

    // 6: cross-tenant employee ID returns safe 404 for list/upload/detail
    public function test_cross_tenant_employee_id_returns_404(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();
        $userA = $this->userWithPermissions($tenantA, 'documents.view', 'documents.upload');
        $employeeB = Employee::factory()->recycle($tenantB)->create(['tenant_id' => $tenantB->id]);
        $documentB = EmployeeDocument::factory()->recycle($tenantB)->create(['tenant_id' => $tenantB->id, 'employee_id' => $employeeB->id]);

        $this->actingAs($userA)->get($this->url($tenantA, "employees/{$employeeB->id}/documents"))->assertNotFound();
        $this->actingAs($userA)->get($this->url($tenantA, "employees/{$employeeB->id}/documents/upload"))->assertNotFound();
        $this->actingAs($userA)->get($this->url($tenantA, "employees/{$employeeB->id}/documents/{$documentB->id}"))->assertNotFound();
    }

    // 7: cross-tenant document ID (same-tenant employee, document from another tenant) returns safe 404
    public function test_cross_tenant_document_id_returns_404(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();
        $userA = $this->userWithPermissions($tenantA, 'documents.view');
        $employeeA = Employee::factory()->recycle($tenantA)->create(['tenant_id' => $tenantA->id]);
        $employeeB = Employee::factory()->recycle($tenantB)->create(['tenant_id' => $tenantB->id]);
        $documentB = EmployeeDocument::factory()->recycle($tenantB)->create(['tenant_id' => $tenantB->id, 'employee_id' => $employeeB->id]);

        $response = $this->actingAs($userA)->get($this->url($tenantA, "employees/{$employeeA->id}/documents/{$documentB->id}"));

        $response->assertNotFound();
    }

    // Refinement 7: a document belonging to a *different employee in the
    // same tenant* must not be reachable through the wrong employee's
    // route — a distinct failure mode from cross-tenant isolation, since
    // both employees and the document all resolve to the correct tenant.
    public function test_same_tenant_wrong_employee_document_returns_404(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'documents.view');
        $employeeA = Employee::factory()->recycle($tenant)->create(['tenant_id' => $tenant->id]);
        $employeeB = Employee::factory()->recycle($tenant)->create(['tenant_id' => $tenant->id]);
        $documentForB = EmployeeDocument::factory()->recycle($tenant)->create(['tenant_id' => $tenant->id, 'employee_id' => $employeeB->id]);

        $response = $this->actingAs($user)->get($this->url($tenant, "employees/{$employeeA->id}/documents/{$documentForB->id}"));

        $response->assertNotFound();
    }

    // 8/9: shared Inertia props carry only IDs, never document data or private storage paths
    public function test_show_page_props_contain_only_ids_not_document_data(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'documents.view');
        $employee = Employee::factory()->recycle($tenant)->create(['tenant_id' => $tenant->id]);
        $document = EmployeeDocument::factory()->recycle($tenant)->create([
            'tenant_id' => $tenant->id,
            'employee_id' => $employee->id,
            'title' => 'Confidential disciplinary letter',
            'storage_path' => 'employee-documents/should-never-leak/secret.pdf',
        ]);

        $response = $this->actingAs($user)->get($this->url($tenant, "employees/{$employee->id}/documents/{$document->id}"));

        $page = $response->viewData('page');
        $this->assertSame(
            ['employeeId', 'documentId'],
            array_keys(array_diff_key($page['props'], ['errors' => 1, 'auth' => 1, 'tenant' => 1])),
        );
        $encoded = json_encode($page['props']);
        $this->assertStringNotContainsString('Confidential disciplinary letter', $encoded);
        $this->assertStringNotContainsString('should-never-leak', $encoded);
        $this->assertStringNotContainsString('secret.pdf', $encoded);
    }

    public function test_index_page_props_contain_only_employee_id(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'documents.view');
        $employee = Employee::factory()->recycle($tenant)->create(['tenant_id' => $tenant->id]);

        $response = $this->actingAs($user)->get($this->url($tenant, "employees/{$employee->id}/documents"));

        $page = $response->viewData('page');
        $this->assertSame(
            ['employeeId'],
            array_keys(array_diff_key($page['props'], ['errors' => 1, 'auth' => 1, 'tenant' => 1])),
        );
    }
}
