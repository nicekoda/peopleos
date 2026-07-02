<?php

namespace Tests\Feature\DocumentCategories;

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

class DocumentCategoryApiTest extends TestCase
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

    public function test_user_with_permission_can_create_category(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'document_categories.create');

        $response = $this->actingAs($user)->postJson($this->url($tenant, 'document-categories'), [
            'name' => 'Identity Documents',
        ]);

        $response->assertCreated();
        $this->assertDatabaseHas('document_categories', ['name' => 'Identity Documents', 'slug' => 'identity-documents', 'tenant_id' => $tenant->id]);
    }

    public function test_user_without_permission_cannot_create_category(): void
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->create(['tenant_id' => $tenant->id]);

        $response = $this->actingAs($user)->postJson($this->url($tenant, 'document-categories'), [
            'name' => 'Identity Documents',
        ]);

        $response->assertForbidden();
    }

    public function test_user_with_permission_can_list_categories_in_own_tenant(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'document_categories.view');
        DocumentCategory::factory()->count(2)->create(['tenant_id' => $tenant->id]);

        $response = $this->actingAs($user)->getJson($this->url($tenant, 'document-categories'));

        $response->assertOk();
        $this->assertCount(2, $response->json('data'));
    }

    public function test_user_cannot_list_categories_from_another_tenant(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenantA, 'document_categories.view');
        DocumentCategory::factory()->create(['tenant_id' => $tenantA->id, 'name' => 'A Category']);
        DocumentCategory::factory()->create(['tenant_id' => $tenantB->id, 'name' => 'B Category']);

        $response = $this->actingAs($user)->getJson($this->url($tenantA, 'document-categories'));

        $response->assertOk();
        $names = collect($response->json('data'))->pluck('name');
        $this->assertTrue($names->contains('A Category'));
        $this->assertFalse($names->contains('B Category'));
    }

    public function test_user_cannot_view_category_from_another_tenant_by_guessed_id(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenantA, 'document_categories.view');
        $categoryB = DocumentCategory::factory()->create(['tenant_id' => $tenantB->id]);

        $response = $this->actingAs($user)->getJson($this->url($tenantA, "document-categories/{$categoryB->id}"));

        $response->assertNotFound();
    }

    public function test_user_cannot_update_category_from_another_tenant(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenantA, 'document_categories.update');
        $categoryB = DocumentCategory::factory()->create(['tenant_id' => $tenantB->id, 'name' => 'Original']);

        $response = $this->actingAs($user)
            ->patchJson($this->url($tenantA, "document-categories/{$categoryB->id}"), ['name' => 'Hacked']);

        $response->assertNotFound();
        $this->assertSame('Original', $categoryB->fresh()->name);
    }

    public function test_user_cannot_delete_category_from_another_tenant(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenantA, 'document_categories.delete');
        $categoryB = DocumentCategory::factory()->create(['tenant_id' => $tenantB->id]);

        $response = $this->actingAs($user)->deleteJson($this->url($tenantA, "document-categories/{$categoryB->id}"));

        $response->assertNotFound();
        $this->assertNull($categoryB->fresh()->deleted_at);
    }

    public function test_request_body_tenant_id_cannot_force_cross_tenant_creation(): void
    {
        $tenant = Tenant::factory()->create();
        $otherTenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'document_categories.create');

        $response = $this->actingAs($user)->postJson($this->url($tenant, 'document-categories'), [
            'name' => 'Identity Documents',
            'tenant_id' => $otherTenant->id,
        ]);

        $response->assertCreated();
        $this->assertDatabaseHas('document_categories', ['name' => 'Identity Documents', 'tenant_id' => $tenant->id]);
        $this->assertDatabaseMissing('document_categories', ['name' => 'Identity Documents', 'tenant_id' => $otherTenant->id]);
    }

    public function test_name_is_unique_within_tenant(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'document_categories.create');
        DocumentCategory::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Identity Documents']);

        $response = $this->actingAs($user)->postJson($this->url($tenant, 'document-categories'), [
            'name' => 'Identity Documents',
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('name');
    }

    public function test_same_name_can_exist_in_different_tenants(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();
        DocumentCategory::factory()->create(['tenant_id' => $tenantA->id, 'name' => 'Identity Documents']);
        $user = $this->userWithPermissions($tenantB, 'document_categories.create');

        $response = $this->actingAs($user)->postJson($this->url($tenantB, 'document-categories'), [
            'name' => 'Identity Documents',
        ]);

        $response->assertCreated();
    }

    public function test_slug_is_unique_within_tenant(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'document_categories.create');
        DocumentCategory::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Original Name', 'slug' => 'shared-slug']);

        $response = $this->actingAs($user)->postJson($this->url($tenant, 'document-categories'), [
            'name' => 'Different Name',
            'slug' => 'shared-slug',
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('slug');
    }

    public function test_inactive_or_deleted_category_cannot_be_used_for_new_employee_document_upload(): void
    {
        Storage::fake('local');

        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'documents.upload');
        $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);
        $inactiveCategory = DocumentCategory::factory()->inactive()->create(['tenant_id' => $tenant->id]);
        $deletedCategory = DocumentCategory::factory()->create(['tenant_id' => $tenant->id]);
        $deletedCategory->delete();

        $this->actingAs($user)->post($this->url($tenant, "employees/{$employee->id}/documents"), [
            'title' => 'Doc',
            'document_category_id' => $inactiveCategory->id,
            'file' => UploadedFile::fake()->create('doc.pdf', 100, 'application/pdf'),
        ])->assertStatus(422)->assertJsonValidationErrors('document_category_id');

        $this->actingAs($user)->post($this->url($tenant, "employees/{$employee->id}/documents"), [
            'title' => 'Doc',
            'document_category_id' => $deletedCategory->id,
            'file' => UploadedFile::fake()->create('doc.pdf', 100, 'application/pdf'),
        ])->assertStatus(422)->assertJsonValidationErrors('document_category_id');
    }

    public function test_category_used_by_active_document_cannot_be_unsafely_hard_deleted(): void
    {
        Storage::fake('local');

        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'document_categories.delete');
        $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);
        $category = DocumentCategory::factory()->create(['tenant_id' => $tenant->id]);
        $document = EmployeeDocument::factory()->create([
            'tenant_id' => $tenant->id,
            'employee_id' => $employee->id,
            'document_category_id' => $category->id,
        ]);

        $response = $this->actingAs($user)->deleteJson($this->url($tenant, "document-categories/{$category->id}"));

        $response->assertOk();
        // Soft-deleted, not gone — the row (and the document's reference
        // to it) still exists in the database.
        $this->assertSoftDeleted('document_categories', ['id' => $category->id]);
        $this->assertDatabaseHas('employee_documents', ['id' => $document->id, 'document_category_id' => $category->id]);
        // The existing document itself is unaffected.
        $this->assertNull($document->fresh()->deleted_at);
    }

    public function test_create_category_writes_audit_log(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'document_categories.create');

        $this->actingAs($user)->postJson($this->url($tenant, 'document-categories'), [
            'name' => 'Identity Documents',
        ])->assertCreated();

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'document_category.created',
            'module' => 'documents',
            'actor_user_id' => $user->id,
            'tenant_id' => $tenant->id,
        ]);
    }

    public function test_update_category_writes_audit_log(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'document_categories.update');
        $category = DocumentCategory::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Before']);

        $this->actingAs($user)
            ->patchJson($this->url($tenant, "document-categories/{$category->id}"), ['name' => 'After'])
            ->assertOk();

        $log = AuditLog::query()->where('action', 'document_category.updated')->where('auditable_id', $category->id)->first();
        $this->assertNotNull($log);
        $this->assertSame('Before', $log->old_values['name']);
        $this->assertSame('After', $log->new_values['name']);
    }

    public function test_delete_category_writes_audit_log(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'document_categories.delete');
        $category = DocumentCategory::factory()->create(['tenant_id' => $tenant->id]);

        $this->actingAs($user)->deleteJson($this->url($tenant, "document-categories/{$category->id}"))->assertOk();

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'document_category.deleted',
            'module' => 'documents',
            'actor_user_id' => $user->id,
            'auditable_id' => $category->id,
        ]);
    }

    public function test_all_category_routes_include_tenant_matches_middleware(): void
    {
        $categoryRoutes = collect(Route::getRoutes())
            ->filter(fn ($route) => str_starts_with($route->uri(), 'api/v1/document-categories'));

        $this->assertGreaterThanOrEqual(5, $categoryRoutes->count());

        foreach ($categoryRoutes as $route) {
            $this->assertContains(
                'tenant.matches',
                $route->gatherMiddleware(),
                "Route [{$route->uri()}] is missing tenant.matches middleware.",
            );
        }
    }

    public function test_inactive_user_fails_closed(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'document_categories.view');
        $user->update(['status' => User::STATUS_INACTIVE]);

        $response = $this->actingAs($user)->getJson($this->url($tenant, 'document-categories'));

        $response->assertForbidden();
    }

    public function test_user_under_inactive_tenant_fails_closed(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'document_categories.view');
        $tenant->update(['status' => Tenant::STATUS_SUSPENDED]);

        $response = $this->actingAs($user)->getJson($this->url($tenant, 'document-categories'));

        $response->assertForbidden();
    }
}
