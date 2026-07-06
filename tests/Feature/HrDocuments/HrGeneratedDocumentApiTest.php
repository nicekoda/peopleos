<?php

namespace Tests\Feature\HrDocuments;

use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\HrDocumentTemplate;
use App\Models\HrGeneratedDocument;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class HrGeneratedDocumentApiTest extends TestCase
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

    // 1: guest cannot access
    public function test_guest_cannot_access_hr_generated_document_api(): void
    {
        $tenant = Tenant::factory()->create();

        $this->getJson($this->url($tenant, 'hr-generated-documents'))->assertUnauthorized();
    }

    // 11: no permission cannot generate
    public function test_user_without_permission_cannot_generate_document(): void
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);
        $template = HrDocumentTemplate::factory()->create(['tenant_id' => $tenant->id]);

        $response = $this->actingAs($user)->postJson($this->url($tenant, 'hr-generated-documents'), [
            'employee_id' => $employee->id,
            'hr_document_template_id' => $template->id,
        ]);

        $response->assertForbidden();
        $this->assertDatabaseCount('hr_generated_documents', 0);
    }

    // 12: with permission can generate for same-tenant employee
    public function test_user_with_permission_can_generate_document_for_same_tenant_employee(): void
    {
        $tenant = Tenant::factory()->create(['name' => 'Acme Corp']);
        $user = $this->userWithPermissions($tenant, 'hr_generated_documents.generate');
        $employee = Employee::factory()->create(['tenant_id' => $tenant->id, 'first_name' => 'Jane', 'last_name' => 'Doe']);
        $template = HrDocumentTemplate::factory()->create(['tenant_id' => $tenant->id, 'title' => 'Employment Letter']);
        $template->currentVersion->update(['content_template' => 'Dear {{employee.name}}, welcome to {{tenant.name}}.']);

        $response = $this->actingAs($user)->postJson($this->url($tenant, 'hr-generated-documents'), [
            'employee_id' => $employee->id,
            'hr_document_template_id' => $template->id,
        ]);

        $response->assertCreated();
        $this->assertDatabaseHas('hr_generated_documents', [
            'employee_id' => $employee->id,
            'hr_document_template_id' => $template->id,
            'tenant_id' => $tenant->id,
            'title' => 'Employment Letter',
            'status' => 'generated',
        ]);
    }

    // 13: cannot generate for another tenant's employee
    public function test_user_cannot_generate_document_for_another_tenants_employee(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenantA, 'hr_generated_documents.generate');
        $employeeB = Employee::factory()->create(['tenant_id' => $tenantB->id]);
        $templateA = HrDocumentTemplate::factory()->create(['tenant_id' => $tenantA->id]);

        $response = $this->actingAs($user)->postJson($this->url($tenantA, 'hr-generated-documents'), [
            'employee_id' => $employeeB->id,
            'hr_document_template_id' => $templateA->id,
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('employee_id');
        $this->assertDatabaseCount('hr_generated_documents', 0);
    }

    public function test_user_cannot_generate_document_from_another_tenants_template(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenantA, 'hr_generated_documents.generate');
        $employeeA = Employee::factory()->create(['tenant_id' => $tenantA->id]);
        $templateB = HrDocumentTemplate::factory()->create(['tenant_id' => $tenantB->id]);

        $response = $this->actingAs($user)->postJson($this->url($tenantA, 'hr-generated-documents'), [
            'employee_id' => $employeeA->id,
            'hr_document_template_id' => $templateB->id,
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('hr_document_template_id');
    }

    public function test_cannot_generate_from_an_inactive_template(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'hr_generated_documents.generate');
        $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);
        $template = HrDocumentTemplate::factory()->inactive()->create(['tenant_id' => $tenant->id]);

        $response = $this->actingAs($user)->postJson($this->url($tenant, 'hr-generated-documents'), [
            'employee_id' => $employee->id,
            'hr_document_template_id' => $template->id,
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('hr_document_template_id');
    }

    // 14: rendered content stored safely
    public function test_generated_document_stores_rendered_content(): void
    {
        $tenant = Tenant::factory()->create(['name' => 'Acme Corp']);
        $user = $this->userWithPermissions($tenant, 'hr_generated_documents.generate');
        $employee = Employee::factory()->create(['tenant_id' => $tenant->id, 'first_name' => 'Jane', 'last_name' => 'Doe']);
        $template = HrDocumentTemplate::factory()->create(['tenant_id' => $tenant->id]);
        $template->currentVersion->update(['content_template' => 'Dear {{employee.name}}, welcome to {{tenant.name}}. {{employee.unknown}}']);

        $response = $this->actingAs($user)->postJson($this->url($tenant, 'hr-generated-documents'), [
            'employee_id' => $employee->id,
            'hr_document_template_id' => $template->id,
        ]);

        $response->assertCreated();
        $rendered = $response->json('data.rendered_content');
        $this->assertStringContainsString('Dear Jane Doe, welcome to Acme Corp.', $rendered);
        // Unknown placeholder passes through unchanged, never executed.
        $this->assertStringContainsString('{{employee.unknown}}', $rendered);
    }

    // 15: no private paths exposed (content-only MVP has none, but the
    // resource must never grow tenant_id/created_by/updated_by either)
    public function test_generated_document_api_does_not_expose_internal_fields(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'hr_generated_documents.view');
        HrGeneratedDocument::factory()->create(['tenant_id' => $tenant->id]);

        $response = $this->actingAs($user)->getJson($this->url($tenant, 'hr-generated-documents'));
        $body = json_encode($response->json());

        foreach (['created_by', 'updated_by', 'deleted_at', 'storage_path', 'storage_disk'] as $internalKey) {
            $this->assertStringNotContainsString($internalKey, $body, "Response unexpectedly contains '{$internalKey}'.");
        }
        $this->assertStringNotContainsString('"tenant_id"', $body);
    }

    // 16: audit logs
    public function test_generate_document_writes_audit_log_without_full_content(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'hr_generated_documents.generate');
        $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);
        $template = HrDocumentTemplate::factory()->create(['tenant_id' => $tenant->id]);
        $template->currentVersion->update(['content_template' => 'CONFIDENTIAL-MARKER-TEXT {{employee.name}}']);

        $this->actingAs($user)->postJson($this->url($tenant, 'hr-generated-documents'), [
            'employee_id' => $employee->id,
            'hr_document_template_id' => $template->id,
        ])->assertCreated();

        $log = AuditLog::query()->where('action', 'hr_generated_document.generated')->first();
        $this->assertNotNull($log);
        $this->assertSame($tenant->id, $log->tenant_id);
        $this->assertSame($user->id, $log->actor_user_id);

        $logBody = json_encode($log->toArray());
        $this->assertStringNotContainsString('CONFIDENTIAL-MARKER-TEXT', $logBody);
    }

    public function test_update_document_writes_audit_log(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'hr_generated_documents.update');
        $document = HrGeneratedDocument::factory()->create(['tenant_id' => $tenant->id, 'title' => 'Before']);

        $this->actingAs($user)
            ->patchJson($this->url($tenant, "hr-generated-documents/{$document->id}"), ['title' => 'After'])
            ->assertOk();

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'hr_generated_document.updated',
            'auditable_id' => $document->id,
        ]);
    }

    public function test_archive_document_writes_audit_log(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'hr_generated_documents.delete');
        $document = HrGeneratedDocument::factory()->create(['tenant_id' => $tenant->id]);

        $this->actingAs($user)->deleteJson($this->url($tenant, "hr-generated-documents/{$document->id}"))->assertOk();

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'hr_generated_document.archived',
            'auditable_id' => $document->id,
        ]);
        $this->assertSoftDeleted('hr_generated_documents', ['id' => $document->id]);
    }

    // Update is title-only — status can never be smuggled through it.
    public function test_update_ignores_status_field(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'hr_generated_documents.update');
        $document = HrGeneratedDocument::factory()->create(['tenant_id' => $tenant->id, 'status' => 'generated']);

        $this->actingAs($user)->patchJson($this->url($tenant, "hr-generated-documents/{$document->id}"), [
            'title' => 'Updated title',
            'status' => 'archived',
            'rendered_content' => 'tampered',
            'generated_by' => 999,
        ])->assertOk();

        $fresh = $document->fresh();
        $this->assertSame('Updated title', $fresh->title);
        $this->assertSame('generated', $fresh->status->value);
        $this->assertNotSame('tampered', $fresh->rendered_content);
    }

    // Tenant isolation on view/update/archive
    public function test_user_cannot_view_document_from_another_tenant_by_guessed_id(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenantA, 'hr_generated_documents.view');
        $documentB = HrGeneratedDocument::factory()->create(['tenant_id' => $tenantB->id]);

        $this->actingAs($user)->getJson($this->url($tenantA, "hr-generated-documents/{$documentB->id}"))->assertNotFound();
    }

    public function test_platform_super_admin_is_blocked_from_hr_generated_document_api(): void
    {
        $tenant = Tenant::factory()->create();
        $admin = User::factory()->platformAdmin()->create();

        $this->actingAs($admin)->getJson($this->url($tenant, 'hr-generated-documents'))->assertForbidden();
    }

    public function test_employee_id_query_filter_rejects_cross_tenant_employee(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenantA, 'hr_generated_documents.view');
        $employeeB = Employee::factory()->create(['tenant_id' => $tenantB->id]);

        $this->actingAs($user)
            ->getJson($this->url($tenantA, "hr-generated-documents?employee_id={$employeeB->id}"))
            ->assertNotFound();
    }

    public function test_all_hr_document_routes_include_tenant_matches_middleware(): void
    {
        $routes = collect(Route::getRoutes())
            ->filter(fn ($route) => str_starts_with($route->uri(), 'api/v1/hr-generated-documents') || str_starts_with($route->uri(), 'api/v1/hr-document-templates'));

        $this->assertGreaterThanOrEqual(10, $routes->count());

        foreach ($routes as $route) {
            $this->assertContains(
                'tenant.matches',
                $route->gatherMiddleware(),
                "Route [{$route->uri()}] is missing tenant.matches middleware.",
            );
        }
    }
}
