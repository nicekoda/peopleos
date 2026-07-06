<?php

namespace Tests\Feature\HrDocuments;

use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\HrDocumentTemplate;
use App\Models\HrDocumentTemplateVersion;
use App\Models\HrGeneratedDocument;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Checkpoint 36 — HR Document Template Versioning Foundation.
 * HrDocumentTemplate::factory() already creates a published version 1
 * via its configure() hook (see HrDocumentTemplateFactory), so most
 * tests here start from a template that already has real content ready
 * — the same shape every real template has after the Checkpoint 36
 * backfill.
 */
class HrDocumentTemplateVersionApiTest extends TestCase
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
    public function test_guest_cannot_access_template_version_api(): void
    {
        $tenant = Tenant::factory()->create();
        $template = HrDocumentTemplate::factory()->create(['tenant_id' => $tenant->id]);

        $this->getJson($this->url($tenant, "hr-document-templates/{$template->id}/versions"))->assertUnauthorized();
    }

    // 2: no permission cannot list/create/update/publish
    public function test_user_without_permission_cannot_manage_versions(): void
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        $template = HrDocumentTemplate::factory()->create(['tenant_id' => $tenant->id]);
        $version = $template->currentVersion;

        $this->actingAs($user)->getJson($this->url($tenant, "hr-document-templates/{$template->id}/versions"))->assertForbidden();
        $this->actingAs($user)->postJson($this->url($tenant, "hr-document-templates/{$template->id}/versions"), ['content_template' => 'x'])->assertForbidden();
        $this->actingAs($user)->patchJson($this->url($tenant, "hr-document-template-versions/{$version->id}"), ['content_template' => 'x'])->assertForbidden();
        $this->actingAs($user)->postJson($this->url($tenant, "hr-document-template-versions/{$version->id}/publish"))->assertForbidden();
    }

    // 3: with permission can manage same-tenant versions
    public function test_user_with_permission_can_list_versions(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'hr_document_templates.view');
        $template = HrDocumentTemplate::factory()->create(['tenant_id' => $tenant->id]);

        $response = $this->actingAs($user)->getJson($this->url($tenant, "hr-document-templates/{$template->id}/versions"));

        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
    }

    public function test_user_with_permission_can_create_draft_version(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'hr_document_templates.update');
        $template = HrDocumentTemplate::factory()->create(['tenant_id' => $tenant->id]);

        $response = $this->actingAs($user)->postJson($this->url($tenant, "hr-document-templates/{$template->id}/versions"), [
            'content_template' => 'Dear {{employee.name}}, revised wording.',
        ]);

        $response->assertCreated();
        $this->assertSame('draft', $response->json('data.status'));
        $this->assertSame(2, $response->json('data.version_number'));
        $this->assertDatabaseHas('hr_document_template_versions', [
            'hr_document_template_id' => $template->id,
            'version_number' => 2,
            'status' => 'draft',
        ]);
    }

    // 11: draft version can be edited
    public function test_user_with_permission_can_edit_draft_version(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'hr_document_templates.update');
        $template = HrDocumentTemplate::factory()->create(['tenant_id' => $tenant->id]);
        $draft = HrDocumentTemplateVersion::factory()->create([
            'tenant_id' => $tenant->id,
            'hr_document_template_id' => $template->id,
            'version_number' => 2,
        ]);

        $response = $this->actingAs($user)->patchJson($this->url($tenant, "hr-document-template-versions/{$draft->id}"), [
            'content_template' => 'Updated draft wording.',
        ]);

        $response->assertOk();
        $this->assertSame('Updated draft wording.', $draft->fresh()->content_template);
    }

    // 12: published version cannot be edited
    public function test_published_version_cannot_be_edited(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'hr_document_templates.update');
        $template = HrDocumentTemplate::factory()->create(['tenant_id' => $tenant->id]);
        $published = $template->currentVersion;

        $response = $this->actingAs($user)->patchJson($this->url($tenant, "hr-document-template-versions/{$published->id}"), [
            'content_template' => 'Should not be allowed.',
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('content_template');
        $this->assertNotSame('Should not be allowed.', $published->fresh()->content_template);
    }

    public function test_archived_version_cannot_be_edited(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'hr_document_templates.update');
        $template = HrDocumentTemplate::factory()->create(['tenant_id' => $tenant->id]);
        $archived = HrDocumentTemplateVersion::factory()->archived()->create([
            'tenant_id' => $tenant->id,
            'hr_document_template_id' => $template->id,
            'version_number' => 2,
        ]);

        $this->actingAs($user)->patchJson($this->url($tenant, "hr-document-template-versions/{$archived->id}"), [
            'content_template' => 'Should not be allowed.',
        ])->assertStatus(422)->assertJsonValidationErrors('content_template');
    }

    // 10 & 13: publish sets published_at/published_by server-side, old
    // versions archived not deleted
    public function test_user_with_permission_can_publish_a_draft_version(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'hr_document_templates.publish');
        $template = HrDocumentTemplate::factory()->create(['tenant_id' => $tenant->id]);
        $oldVersion = $template->currentVersion;
        $draft = HrDocumentTemplateVersion::factory()->create([
            'tenant_id' => $tenant->id,
            'hr_document_template_id' => $template->id,
            'version_number' => 2,
        ]);

        $response = $this->actingAs($user)->postJson($this->url($tenant, "hr-document-template-versions/{$draft->id}/publish"), [
            // Attempt to smuggle server-only fields — must be ignored.
            'published_at' => '2000-01-01T00:00:00Z',
            'published_by' => 999999,
        ]);

        $response->assertOk();
        $draft->refresh();
        $this->assertSame('published', $draft->status->value);
        $this->assertSame($user->id, $draft->published_by);
        $this->assertNotNull($draft->published_at);
        $this->assertNotSame('2000-01-01T00:00:00+00:00', $draft->published_at->toIso8601String());

        // Template now points at the new version.
        $this->assertSame($draft->id, $template->fresh()->current_version_id);

        // Old version demoted to archived, NOT deleted.
        $oldVersion->refresh();
        $this->assertSame('archived', $oldVersion->status->value);
        $this->assertNull($oldVersion->deleted_at);
    }

    public function test_user_without_publish_permission_cannot_publish(): void
    {
        $tenant = Tenant::factory()->create();
        // Has .update but not .publish — a real, meaningful split.
        $user = $this->userWithPermissions($tenant, 'hr_document_templates.update');
        $template = HrDocumentTemplate::factory()->create(['tenant_id' => $tenant->id]);
        $draft = HrDocumentTemplateVersion::factory()->create([
            'tenant_id' => $tenant->id,
            'hr_document_template_id' => $template->id,
            'version_number' => 2,
        ]);

        $this->actingAs($user)->postJson($this->url($tenant, "hr-document-template-versions/{$draft->id}/publish"))->assertForbidden();
    }

    // draft-only delete rule
    public function test_draft_version_can_be_deleted(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'hr_document_templates.delete');
        $template = HrDocumentTemplate::factory()->create(['tenant_id' => $tenant->id]);
        $draft = HrDocumentTemplateVersion::factory()->create([
            'tenant_id' => $tenant->id,
            'hr_document_template_id' => $template->id,
            'version_number' => 2,
        ]);

        $this->actingAs($user)->deleteJson($this->url($tenant, "hr-document-template-versions/{$draft->id}"))->assertOk();
        $this->assertSoftDeleted('hr_document_template_versions', ['id' => $draft->id]);
    }

    public function test_published_version_cannot_be_deleted(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'hr_document_templates.delete');
        $template = HrDocumentTemplate::factory()->create(['tenant_id' => $tenant->id]);
        $published = $template->currentVersion;

        $this->actingAs($user)->deleteJson($this->url($tenant, "hr-document-template-versions/{$published->id}"))->assertStatus(422);
        $this->assertNull($published->fresh()->deleted_at);
    }

    // 4: tenant isolation
    public function test_user_cannot_list_versions_of_another_tenants_template(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenantA, 'hr_document_templates.view');
        $templateB = HrDocumentTemplate::factory()->create(['tenant_id' => $tenantB->id]);

        $this->actingAs($user)->getJson($this->url($tenantA, "hr-document-templates/{$templateB->id}/versions"))->assertNotFound();
    }

    public function test_user_cannot_view_or_edit_another_tenants_version(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();
        $userA = $this->userWithPermissions($tenantA, 'hr_document_templates.view', 'hr_document_templates.update');
        $templateB = HrDocumentTemplate::factory()->create(['tenant_id' => $tenantB->id]);
        $versionB = $templateB->currentVersion;

        $this->actingAs($userA)->getJson($this->url($tenantA, "hr-document-template-versions/{$versionB->id}"))->assertNotFound();
        $this->actingAs($userA)
            ->patchJson($this->url($tenantA, "hr-document-template-versions/{$versionB->id}"), ['content_template' => 'Hacked'])
            ->assertNotFound();
    }

    // 5: platform super admin blocked
    public function test_platform_super_admin_is_blocked_from_template_version_api(): void
    {
        $tenant = Tenant::factory()->create();
        $admin = User::factory()->platformAdmin()->create();
        $template = HrDocumentTemplate::factory()->create(['tenant_id' => $tenant->id]);

        $this->actingAs($admin)->getJson($this->url($tenant, "hr-document-templates/{$template->id}/versions"))->assertForbidden();
    }

    // 14: resource safety
    public function test_version_resource_does_not_expose_internal_fields(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'hr_document_templates.view');
        HrDocumentTemplate::factory()->create(['tenant_id' => $tenant->id]);

        $response = $this->actingAs($user)->getJson($this->url($tenant, 'hr-document-templates').'/'.HrDocumentTemplate::query()->first()->id.'/versions');
        $body = json_encode($response->json());

        foreach (['created_by', 'updated_by', 'deleted_at'] as $internalKey) {
            $this->assertStringNotContainsString($internalKey, $body, "Response unexpectedly contains '{$internalKey}'.");
        }
        $this->assertStringNotContainsString('"tenant_id"', $body);
    }

    // 15: audit logging
    public function test_create_version_writes_audit_log(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'hr_document_templates.update');
        $template = HrDocumentTemplate::factory()->create(['tenant_id' => $tenant->id]);

        $this->actingAs($user)->postJson($this->url($tenant, "hr-document-templates/{$template->id}/versions"), [
            'content_template' => 'New draft wording.',
        ])->assertCreated();

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'hr_document_template_version.created',
            'module' => 'hr_documents',
            'actor_user_id' => $user->id,
            'tenant_id' => $tenant->id,
        ]);
    }

    public function test_update_version_writes_audit_log(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'hr_document_templates.update');
        $template = HrDocumentTemplate::factory()->create(['tenant_id' => $tenant->id]);
        $draft = HrDocumentTemplateVersion::factory()->create([
            'tenant_id' => $tenant->id,
            'hr_document_template_id' => $template->id,
            'version_number' => 2,
        ]);

        $this->actingAs($user)->patchJson($this->url($tenant, "hr-document-template-versions/{$draft->id}"), [
            'content_template' => 'Changed.',
        ])->assertOk();

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'hr_document_template_version.updated',
            'auditable_id' => $draft->id,
        ]);
    }

    public function test_publish_version_writes_audit_log(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'hr_document_templates.publish');
        $template = HrDocumentTemplate::factory()->create(['tenant_id' => $tenant->id]);
        $draft = HrDocumentTemplateVersion::factory()->create([
            'tenant_id' => $tenant->id,
            'hr_document_template_id' => $template->id,
            'version_number' => 2,
        ]);

        $this->actingAs($user)->postJson($this->url($tenant, "hr-document-template-versions/{$draft->id}/publish"))->assertOk();

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'hr_document_template_version.published',
            'auditable_id' => $draft->id,
        ]);
    }

    public function test_delete_draft_version_writes_audit_log(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'hr_document_templates.delete');
        $template = HrDocumentTemplate::factory()->create(['tenant_id' => $tenant->id]);
        $draft = HrDocumentTemplateVersion::factory()->create([
            'tenant_id' => $tenant->id,
            'hr_document_template_id' => $template->id,
            'version_number' => 2,
        ]);

        $this->actingAs($user)->deleteJson($this->url($tenant, "hr-document-template-versions/{$draft->id}"))->assertOk();

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'hr_document_template_version.archived',
            'auditable_id' => $draft->id,
        ]);
    }

    // audit metadata never contains the full wording
    public function test_version_audit_logs_do_not_contain_full_content(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'hr_document_templates.update');
        $template = HrDocumentTemplate::factory()->create(['tenant_id' => $tenant->id]);

        $this->actingAs($user)->postJson($this->url($tenant, "hr-document-templates/{$template->id}/versions"), [
            'content_template' => 'SECRET-WORDING-MARKER {{employee.name}}',
        ])->assertCreated();

        $log = AuditLog::query()->where('action', 'hr_document_template_version.created')->first();
        $this->assertNotNull($log);
        $this->assertStringNotContainsString('SECRET-WORDING-MARKER', json_encode($log->toArray()));
    }

    // 8: new generated document references the template version used
    public function test_generated_document_references_template_version(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'hr_generated_documents.generate');
        $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);
        $template = HrDocumentTemplate::factory()->create(['tenant_id' => $tenant->id]);

        $response = $this->actingAs($user)->postJson($this->url($tenant, 'hr-generated-documents'), [
            'employee_id' => $employee->id,
            'hr_document_template_id' => $template->id,
        ]);

        $response->assertCreated();
        $this->assertSame($template->current_version_id, $response->json('data.hr_document_template_version_id'));
    }

    // generation fails cleanly for a template that's active but never
    // had a version published (a real, testable edge case)
    public function test_cannot_generate_from_a_template_with_no_published_version(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'hr_generated_documents.generate');
        $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);
        $template = HrDocumentTemplate::factory()->create(['tenant_id' => $tenant->id]);
        $template->update(['current_version_id' => null]);

        $response = $this->actingAs($user)->postJson($this->url($tenant, 'hr-generated-documents'), [
            'employee_id' => $employee->id,
            'hr_document_template_id' => $template->id,
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('hr_document_template_id');
    }

    // 7: existing generated documents (nullable version reference) keep working
    public function test_generated_document_with_no_version_reference_still_works(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'hr_generated_documents.view');
        $document = HrGeneratedDocument::factory()->create([
            'tenant_id' => $tenant->id,
            'hr_document_template_version_id' => null,
        ]);

        $this->actingAs($user)->getJson($this->url($tenant, "hr-generated-documents/{$document->id}"))->assertOk();
    }

    public function test_all_version_routes_include_tenant_matches_middleware(): void
    {
        $routes = collect(Route::getRoutes())
            ->filter(fn ($route) => str_contains($route->uri(), 'hr-document-template-versions') || str_contains($route->uri(), 'hr-document-templates/{hrDocumentTemplate}/versions'));

        $this->assertGreaterThanOrEqual(6, $routes->count());

        foreach ($routes as $route) {
            $this->assertContains('tenant.matches', $route->gatherMiddleware());
        }
    }
}
