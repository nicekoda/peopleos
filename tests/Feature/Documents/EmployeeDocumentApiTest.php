<?php

namespace Tests\Feature\Documents;

use App\Http\Requests\Document\StoreEmployeeDocumentRequest;
use App\Models\AuditLog;
use App\Models\DocumentCategory;
use App\Models\Employee;
use App\Models\EmployeeDocument;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class EmployeeDocumentApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
    }

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

    public function test_user_with_upload_permission_can_upload_employee_document(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'documents.upload');
        $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);

        $response = $this->actingAs($user)->post($this->url($tenant, "employees/{$employee->id}/documents"), [
            'title' => 'Passport Copy',
            'file' => UploadedFile::fake()->create('passport.pdf', 100, 'application/pdf'),
        ]);

        $response->assertCreated();
        $this->assertDatabaseHas('employee_documents', ['title' => 'Passport Copy', 'employee_id' => $employee->id]);
    }

    public function test_user_without_upload_permission_cannot_upload(): void
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);

        $response = $this->actingAs($user)->post($this->url($tenant, "employees/{$employee->id}/documents"), [
            'title' => 'Passport Copy',
            'file' => UploadedFile::fake()->create('passport.pdf', 100, 'application/pdf'),
        ]);

        $response->assertForbidden();
    }

    public function test_user_with_view_permission_can_list_own_tenant_employee_documents(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'documents.view');
        $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);
        EmployeeDocument::factory()->create(['tenant_id' => $tenant->id, 'employee_id' => $employee->id]);

        $response = $this->actingAs($user)->getJson($this->url($tenant, "employees/{$employee->id}/documents"));

        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
    }

    public function test_user_without_view_permission_cannot_list_documents(): void
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);

        $response = $this->actingAs($user)->getJson($this->url($tenant, "employees/{$employee->id}/documents"));

        $response->assertForbidden();
    }

    public function test_user_with_download_permission_can_download_own_tenant_document(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'documents.download');
        $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);
        $document = EmployeeDocument::factory()->create(['tenant_id' => $tenant->id, 'employee_id' => $employee->id]);

        $response = $this->actingAs($user)->get($this->url($tenant, "employees/{$employee->id}/documents/{$document->id}/download"));

        $response->assertOk();
    }

    public function test_user_without_download_permission_cannot_download(): void
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);
        $document = EmployeeDocument::factory()->create(['tenant_id' => $tenant->id, 'employee_id' => $employee->id]);

        $response = $this->actingAs($user)->get($this->url($tenant, "employees/{$employee->id}/documents/{$document->id}/download"));

        $response->assertForbidden();
    }

    public function test_user_with_delete_permission_can_soft_delete_document(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'documents.delete');
        $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);
        $document = EmployeeDocument::factory()->create(['tenant_id' => $tenant->id, 'employee_id' => $employee->id]);

        $response = $this->actingAs($user)->deleteJson($this->url($tenant, "employees/{$employee->id}/documents/{$document->id}"));

        $response->assertOk();
        $this->assertNotNull($document->fresh()->deleted_at);
    }

    public function test_user_without_delete_permission_cannot_delete(): void
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);
        $document = EmployeeDocument::factory()->create(['tenant_id' => $tenant->id, 'employee_id' => $employee->id]);

        $response = $this->actingAs($user)->deleteJson($this->url($tenant, "employees/{$employee->id}/documents/{$document->id}"));

        $response->assertForbidden();
        $this->assertNull($document->fresh()->deleted_at);
    }

    public function test_tenant_a_cannot_list_tenant_b_employee_documents(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenantA, 'documents.view');
        $employeeB = Employee::factory()->create(['tenant_id' => $tenantB->id]);
        EmployeeDocument::factory()->create(['tenant_id' => $tenantB->id, 'employee_id' => $employeeB->id]);

        $response = $this->actingAs($user)->getJson($this->url($tenantA, "employees/{$employeeB->id}/documents"));

        $response->assertNotFound();
    }

    public function test_tenant_a_cannot_view_tenant_b_document_metadata(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenantA, 'documents.view');
        $employeeA = Employee::factory()->create(['tenant_id' => $tenantA->id]);
        $employeeB = Employee::factory()->create(['tenant_id' => $tenantB->id]);
        $documentB = EmployeeDocument::factory()->create(['tenant_id' => $tenantB->id, 'employee_id' => $employeeB->id]);

        $response = $this->actingAs($user)
            ->getJson($this->url($tenantA, "employees/{$employeeA->id}/documents/{$documentB->id}"));

        $response->assertNotFound();
    }

    public function test_tenant_a_cannot_download_tenant_b_document_by_guessed_id(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenantA, 'documents.download');
        $employeeA = Employee::factory()->create(['tenant_id' => $tenantA->id]);
        $employeeB = Employee::factory()->create(['tenant_id' => $tenantB->id]);
        $documentB = EmployeeDocument::factory()->create(['tenant_id' => $tenantB->id, 'employee_id' => $employeeB->id]);

        $response = $this->actingAs($user)
            ->get($this->url($tenantA, "employees/{$employeeA->id}/documents/{$documentB->id}/download"));

        $response->assertNotFound();
    }

    public function test_tenant_a_cannot_delete_tenant_b_document(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenantA, 'documents.delete');
        $employeeA = Employee::factory()->create(['tenant_id' => $tenantA->id]);
        $employeeB = Employee::factory()->create(['tenant_id' => $tenantB->id]);
        $documentB = EmployeeDocument::factory()->create(['tenant_id' => $tenantB->id, 'employee_id' => $employeeB->id]);

        $response = $this->actingAs($user)
            ->deleteJson($this->url($tenantA, "employees/{$employeeA->id}/documents/{$documentB->id}"));

        $response->assertNotFound();
        $this->assertNull($documentB->fresh()->deleted_at);
    }

    public function test_document_must_belong_to_employee_in_route(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'documents.view');
        $employeeOne = Employee::factory()->create(['tenant_id' => $tenant->id]);
        $employeeTwo = Employee::factory()->create(['tenant_id' => $tenant->id]);
        $documentForTwo = EmployeeDocument::factory()->create(['tenant_id' => $tenant->id, 'employee_id' => $employeeTwo->id]);

        // Same tenant, correct document ID, but wrong employee in the route.
        $response = $this->actingAs($user)
            ->getJson($this->url($tenant, "employees/{$employeeOne->id}/documents/{$documentForTwo->id}"));

        $response->assertNotFound();
    }

    public function test_category_from_another_tenant_is_rejected(): void
    {
        $tenant = Tenant::factory()->create();
        $otherTenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'documents.upload');
        $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);
        $foreignCategory = DocumentCategory::factory()->create(['tenant_id' => $otherTenant->id]);

        $response = $this->actingAs($user)->post($this->url($tenant, "employees/{$employee->id}/documents"), [
            'title' => 'Passport Copy',
            'document_category_id' => $foreignCategory->id,
            'file' => UploadedFile::fake()->create('passport.pdf', 100, 'application/pdf'),
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('document_category_id');
    }

    public function test_request_body_tenant_id_cannot_force_cross_tenant_creation(): void
    {
        $tenant = Tenant::factory()->create();
        $otherTenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'documents.upload');
        $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);

        $response = $this->actingAs($user)->post($this->url($tenant, "employees/{$employee->id}/documents"), [
            'title' => 'Passport Copy',
            'tenant_id' => $otherTenant->id,
            'file' => UploadedFile::fake()->create('passport.pdf', 100, 'application/pdf'),
        ]);

        $response->assertCreated();
        $this->assertDatabaseHas('employee_documents', ['title' => 'Passport Copy', 'tenant_id' => $tenant->id]);
        $this->assertDatabaseMissing('employee_documents', ['title' => 'Passport Copy', 'tenant_id' => $otherTenant->id]);
    }

    public function test_invalid_file_type_is_rejected(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'documents.upload');
        $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);

        $response = $this->actingAs($user)->post($this->url($tenant, "employees/{$employee->id}/documents"), [
            'title' => 'Suspicious File',
            'file' => UploadedFile::fake()->create('archive.zip', 100, 'application/zip'),
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('file');
    }

    public function test_unsafe_file_extension_is_rejected(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'documents.upload');
        $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);

        $response = $this->actingAs($user)->post($this->url($tenant, "employees/{$employee->id}/documents"), [
            'title' => 'Malware',
            'file' => UploadedFile::fake()->create('installer.exe', 100, 'application/x-msdownload'),
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('file');
    }

    public function test_oversized_file_is_rejected(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'documents.upload');
        $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);

        $response = $this->actingAs($user)->post($this->url($tenant, "employees/{$employee->id}/documents"), [
            'title' => 'Huge File',
            'file' => UploadedFile::fake()->create('big.pdf', StoreEmployeeDocumentRequest::MAX_FILE_SIZE_KB + 1024, 'application/pdf'),
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('file');
    }

    public function test_expiry_date_is_required_when_category_requires_it(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'documents.upload');
        $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);
        $category = DocumentCategory::factory()->requiresExpiryDate()->create(['tenant_id' => $tenant->id]);

        $response = $this->actingAs($user)->post($this->url($tenant, "employees/{$employee->id}/documents"), [
            'title' => 'Work Permit',
            'document_category_id' => $category->id,
            'file' => UploadedFile::fake()->create('permit.pdf', 100, 'application/pdf'),
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('expiry_date');
    }

    public function test_deleted_document_cannot_be_downloaded(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'documents.download');
        $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);
        $document = EmployeeDocument::factory()->create(['tenant_id' => $tenant->id, 'employee_id' => $employee->id]);
        $document->delete();

        $response = $this->actingAs($user)->get($this->url($tenant, "employees/{$employee->id}/documents/{$document->id}/download"));

        $response->assertNotFound();
    }

    public function test_upload_creates_audit_log(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'documents.upload');
        $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);

        $this->actingAs($user)->post($this->url($tenant, "employees/{$employee->id}/documents"), [
            'title' => 'Passport Copy',
            'file' => UploadedFile::fake()->create('passport.pdf', 100, 'application/pdf'),
        ])->assertCreated();

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'document.uploaded',
            'module' => 'documents',
            'actor_user_id' => $user->id,
            'tenant_id' => $tenant->id,
        ]);
    }

    public function test_download_creates_audit_log(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'documents.download');
        $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);
        $document = EmployeeDocument::factory()->create(['tenant_id' => $tenant->id, 'employee_id' => $employee->id]);

        $this->actingAs($user)->get($this->url($tenant, "employees/{$employee->id}/documents/{$document->id}/download"))->assertOk();

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'document.downloaded',
            'module' => 'documents',
            'actor_user_id' => $user->id,
            'auditable_id' => $document->id,
        ]);
    }

    public function test_delete_creates_audit_log(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'documents.delete');
        $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);
        $document = EmployeeDocument::factory()->create(['tenant_id' => $tenant->id, 'employee_id' => $employee->id]);

        $this->actingAs($user)->deleteJson($this->url($tenant, "employees/{$employee->id}/documents/{$document->id}"))->assertOk();

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'document.deleted',
            'module' => 'documents',
            'actor_user_id' => $user->id,
            'auditable_id' => $document->id,
        ]);
    }

    public function test_audit_log_does_not_store_file_contents(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'documents.upload');
        $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);

        $this->actingAs($user)->post($this->url($tenant, "employees/{$employee->id}/documents"), [
            'title' => 'Passport Copy',
            'file' => UploadedFile::fake()->create('passport.pdf', 100, 'application/pdf'),
        ])->assertCreated();

        $log = AuditLog::query()->where('action', 'document.uploaded')->latest('id')->firstOrFail();
        $payload = json_encode([$log->old_values, $log->new_values, $log->metadata]);

        // No raw file bytes, and no full storage_path — only safe metadata.
        $this->assertArrayNotHasKey('storage_path', $log->new_values ?? []);
        $this->assertArrayNotHasKey('stored_filename', $log->new_values ?? []);
        $this->assertArrayNotHasKey('file_contents', $log->new_values ?? []);
        $this->assertLessThan(2000, strlen($payload), 'Audit log payload is suspiciously large for metadata-only logging.');
    }

    public function test_all_document_routes_include_tenant_matches_middleware(): void
    {
        $documentRoutes = collect(Route::getRoutes())
            ->filter(fn ($route) => str_starts_with($route->uri(), 'api/v1/employees/{employee}/documents'));

        $this->assertGreaterThanOrEqual(5, $documentRoutes->count());

        foreach ($documentRoutes as $route) {
            $this->assertContains(
                'tenant.matches',
                $route->gatherMiddleware(),
                "Route [{$route->uri()}] is missing tenant.matches middleware.",
            );
            $this->assertContains('auth', $route->gatherMiddleware(), "Route [{$route->uri()}] is missing auth middleware.");
        }
    }

    public function test_user_with_view_sensitive_can_see_sensitive_documents(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'documents.view', 'documents.view_sensitive');
        $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);
        $document = EmployeeDocument::factory()->create([
            'tenant_id' => $tenant->id,
            'employee_id' => $employee->id,
            'is_sensitive' => true,
        ]);

        $response = $this->actingAs($user)
            ->getJson($this->url($tenant, "employees/{$employee->id}/documents/{$document->id}"));

        $response->assertOk();
    }

    public function test_user_without_view_sensitive_cannot_see_sensitive_documents(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'documents.view');
        $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);
        $document = EmployeeDocument::factory()->create([
            'tenant_id' => $tenant->id,
            'employee_id' => $employee->id,
            'is_sensitive' => true,
        ]);

        $response = $this->actingAs($user)
            ->getJson($this->url($tenant, "employees/{$employee->id}/documents/{$document->id}"));

        $response->assertNotFound();
    }

    public function test_sensitive_documents_excluded_from_list_without_view_sensitive(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'documents.view');
        $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);
        EmployeeDocument::factory()->create(['tenant_id' => $tenant->id, 'employee_id' => $employee->id, 'is_sensitive' => true]);
        EmployeeDocument::factory()->create(['tenant_id' => $tenant->id, 'employee_id' => $employee->id, 'is_sensitive' => false]);

        $response = $this->actingAs($user)->getJson($this->url($tenant, "employees/{$employee->id}/documents"));

        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
    }
}
