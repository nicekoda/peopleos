<?php

namespace Tests\Feature\HrDocuments;

use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\HrDocumentTemplate;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Checkpoint 38 — HR Document Template Library & Starter Templates.
 * Duplication reuses hr_document_templates.create (not a new
 * permission) — duplicating is creating a new template pre-filled from
 * an existing one.
 */
class HrDocumentTemplateDuplicateApiTest extends TestCase
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

    // 4: no create permission cannot duplicate
    public function test_user_without_create_permission_cannot_duplicate(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'hr_document_templates.view');
        $template = HrDocumentTemplate::factory()->create(['tenant_id' => $tenant->id]);

        $this->actingAs($user)->postJson($this->url($tenant, "hr-document-templates/{$template->id}/duplicate"))->assertForbidden();
    }

    // 5/6/7: with create permission can duplicate, unique slug, version 1
    // copied from source current version and published
    public function test_user_with_create_permission_can_duplicate_template(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'hr_document_templates.create');
        $source = HrDocumentTemplate::factory()->create(['tenant_id' => $tenant->id, 'title' => 'Employment Letter']);
        $source->currentVersion->update(['content_template' => 'Dear {{employee.name}}, source content.']);

        $response = $this->actingAs($user)->postJson($this->url($tenant, "hr-document-templates/{$source->id}/duplicate"));

        $response->assertCreated();
        $this->assertSame('Employment Letter (Copy)', $response->json('data.title'));
        $this->assertSame('employment-letter-copy', $response->json('data.slug'));
        $this->assertSame($source->document_type->value, $response->json('data.document_type'));
        $this->assertSame('active', $response->json('data.status'));
        $this->assertNotNull($response->json('data.current_version_id'));
        $this->assertNotSame($source->id, $response->json('data.id'));

        $duplicate = HrDocumentTemplate::query()->with('currentVersion')->find($response->json('data.id'));
        $this->assertSame('Dear {{employee.name}}, source content.', $duplicate->currentVersion->content_template);
        $this->assertSame('published', $duplicate->currentVersion->status->value);
        $this->assertSame(1, $duplicate->currentVersion->version_number);
    }

    public function test_duplicating_twice_produces_unique_titles_and_slugs(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'hr_document_templates.create');
        $source = HrDocumentTemplate::factory()->create(['tenant_id' => $tenant->id, 'title' => 'Offer Letter']);

        $first = $this->actingAs($user)->postJson($this->url($tenant, "hr-document-templates/{$source->id}/duplicate"));
        $second = $this->actingAs($user)->postJson($this->url($tenant, "hr-document-templates/{$source->id}/duplicate"));

        $first->assertCreated();
        $second->assertCreated();
        $this->assertSame('Offer Letter (Copy)', $first->json('data.title'));
        $this->assertSame('Offer Letter (Copy 2)', $second->json('data.title'));
        $this->assertNotSame($first->json('data.slug'), $second->json('data.slug'));
    }

    // 8: tenant isolation
    public function test_user_cannot_duplicate_another_tenants_template(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenantA, 'hr_document_templates.create');
        $templateB = HrDocumentTemplate::factory()->create(['tenant_id' => $tenantB->id]);

        $this->actingAs($user)->postJson($this->url($tenantA, "hr-document-templates/{$templateB->id}/duplicate"))->assertNotFound();
        $this->assertDatabaseMissing('hr_document_templates', ['title' => $templateB->title.' (Copy)']);
    }

    // 9: platform super admin blocked
    public function test_platform_super_admin_is_blocked_from_duplicating(): void
    {
        $tenant = Tenant::factory()->create();
        $admin = User::factory()->platformAdmin()->create();
        $template = HrDocumentTemplate::factory()->create(['tenant_id' => $tenant->id]);

        $this->actingAs($admin)->postJson($this->url($tenant, "hr-document-templates/{$template->id}/duplicate"))->assertForbidden();
    }

    // 10: audit log
    public function test_duplicate_writes_audit_log_without_full_content(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'hr_document_templates.create');
        $source = HrDocumentTemplate::factory()->create(['tenant_id' => $tenant->id]);
        $source->currentVersion->update(['content_template' => 'CONFIDENTIAL-SOURCE-CONTENT-MARKER']);

        $response = $this->actingAs($user)->postJson($this->url($tenant, "hr-document-templates/{$source->id}/duplicate"));
        $response->assertCreated();

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'hr_document_template.duplicated',
            'auditable_id' => $response->json('data.id'),
            'actor_user_id' => $user->id,
            'tenant_id' => $tenant->id,
        ]);

        $log = AuditLog::query()->where('action', 'hr_document_template.duplicated')->first();
        $this->assertStringNotContainsString('CONFIDENTIAL-SOURCE-CONTENT-MARKER', json_encode($log->toArray()));
        $this->assertSame($source->id, $log->metadata['source_template_id'] ?? null);
    }

    public function test_cannot_duplicate_a_template_with_no_published_version(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'hr_document_templates.create');
        $source = HrDocumentTemplate::factory()->create(['tenant_id' => $tenant->id]);
        $source->update(['current_version_id' => null]);

        $this->actingAs($user)->postJson($this->url($tenant, "hr-document-templates/{$source->id}/duplicate"))->assertStatus(422);
    }

    // 11: existing generation flow works from a duplicate
    public function test_generated_document_can_be_created_from_a_duplicated_template(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'hr_document_templates.create', 'hr_generated_documents.generate');
        $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);
        $source = HrDocumentTemplate::factory()->create(['tenant_id' => $tenant->id]);

        $dup = $this->actingAs($user)->postJson($this->url($tenant, "hr-document-templates/{$source->id}/duplicate"));
        $dup->assertCreated();
        $duplicateId = $dup->json('data.id');

        $response = $this->actingAs($user)->postJson($this->url($tenant, 'hr-generated-documents'), [
            'employee_id' => $employee->id,
            'hr_document_template_id' => $duplicateId,
        ]);

        $response->assertCreated();
        $this->assertSame($duplicateId, $response->json('data.hr_document_template_id'));
    }

    public function test_all_hr_document_template_routes_still_include_tenant_matches_middleware(): void
    {
        $routes = collect(Route::getRoutes())
            ->filter(fn ($route) => str_contains($route->uri(), 'hr-document-templates') && str_contains($route->uri(), 'duplicate'));

        $this->assertGreaterThanOrEqual(1, $routes->count());

        foreach ($routes as $route) {
            $this->assertContains('tenant.matches', $route->gatherMiddleware());
        }
    }
}
