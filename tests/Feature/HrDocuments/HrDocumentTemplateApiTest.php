<?php

namespace Tests\Feature\HrDocuments;

use App\Models\HrDocumentTemplate;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HrDocumentTemplateApiTest extends TestCase
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
    public function test_guest_cannot_access_hr_document_template_api(): void
    {
        $tenant = Tenant::factory()->create();

        $this->getJson($this->url($tenant, 'hr-document-templates'))->assertUnauthorized();
    }

    // 2: no permission cannot list/view
    public function test_user_without_permission_cannot_list_templates(): void
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->create(['tenant_id' => $tenant->id]);

        $this->actingAs($user)->getJson($this->url($tenant, 'hr-document-templates'))->assertForbidden();
    }

    public function test_user_without_permission_cannot_view_template(): void
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        $template = HrDocumentTemplate::factory()->create(['tenant_id' => $tenant->id]);

        $this->actingAs($user)->getJson($this->url($tenant, "hr-document-templates/{$template->id}"))->assertForbidden();
    }

    // 3: with permission can list/view
    public function test_user_with_permission_can_list_templates(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'hr_document_templates.view');
        HrDocumentTemplate::factory()->count(2)->create(['tenant_id' => $tenant->id]);

        $response = $this->actingAs($user)->getJson($this->url($tenant, 'hr-document-templates'));

        $response->assertOk();
        $this->assertCount(2, $response->json('data'));
    }

    public function test_user_with_permission_can_view_template(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'hr_document_templates.view');
        $template = HrDocumentTemplate::factory()->create(['tenant_id' => $tenant->id]);

        $this->actingAs($user)->getJson($this->url($tenant, "hr-document-templates/{$template->id}"))->assertOk();
    }

    // 4: no permission cannot create/update/archive
    public function test_user_without_permission_cannot_create_template(): void
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->create(['tenant_id' => $tenant->id]);

        $this->actingAs($user)->postJson($this->url($tenant, 'hr-document-templates'), [
            'title' => 'Employment Letter',
            'document_type' => 'employment_letter',
            'content_template' => 'Dear {{employee.name}}',
        ])->assertForbidden();
    }

    public function test_user_without_permission_cannot_update_template(): void
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        $template = HrDocumentTemplate::factory()->create(['tenant_id' => $tenant->id]);

        $this->actingAs($user)
            ->patchJson($this->url($tenant, "hr-document-templates/{$template->id}"), ['title' => 'Hacked'])
            ->assertForbidden();
    }

    public function test_user_without_permission_cannot_archive_template(): void
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        $template = HrDocumentTemplate::factory()->create(['tenant_id' => $tenant->id]);

        $this->actingAs($user)->deleteJson($this->url($tenant, "hr-document-templates/{$template->id}"))->assertForbidden();
    }

    // 5: with permission can create/update/archive
    public function test_user_with_permission_can_create_template(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'hr_document_templates.create');

        $response = $this->actingAs($user)->postJson($this->url($tenant, 'hr-document-templates'), [
            'title' => 'Employment Letter',
            'document_type' => 'employment_letter',
            'content_template' => 'Dear {{employee.name}}, welcome to {{tenant.name}}.',
        ]);

        $response->assertCreated();
        $this->assertDatabaseHas('hr_document_templates', [
            'title' => 'Employment Letter',
            'slug' => 'employment-letter',
            'tenant_id' => $tenant->id,
            'status' => 'active',
        ]);
    }

    public function test_user_with_permission_can_update_template(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'hr_document_templates.update');
        $template = HrDocumentTemplate::factory()->create(['tenant_id' => $tenant->id, 'title' => 'Before']);

        $response = $this->actingAs($user)
            ->patchJson($this->url($tenant, "hr-document-templates/{$template->id}"), ['title' => 'After']);

        $response->assertOk();
        $this->assertSame('After', $template->fresh()->title);
    }

    public function test_user_with_permission_can_archive_template(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'hr_document_templates.delete');
        $template = HrDocumentTemplate::factory()->create(['tenant_id' => $tenant->id]);

        $response = $this->actingAs($user)->deleteJson($this->url($tenant, "hr-document-templates/{$template->id}"));

        $response->assertOk();
        $this->assertSoftDeleted('hr_document_templates', ['id' => $template->id]);
    }

    // 6: tenant isolation
    public function test_user_cannot_list_templates_from_another_tenant(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenantA, 'hr_document_templates.view');
        HrDocumentTemplate::factory()->create(['tenant_id' => $tenantA->id, 'title' => 'A Template']);
        HrDocumentTemplate::factory()->create(['tenant_id' => $tenantB->id, 'title' => 'B Template']);

        $response = $this->actingAs($user)->getJson($this->url($tenantA, 'hr-document-templates'));

        $titles = collect($response->json('data'))->pluck('title');
        $this->assertTrue($titles->contains('A Template'));
        $this->assertFalse($titles->contains('B Template'));
    }

    public function test_user_cannot_view_template_from_another_tenant_by_guessed_id(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenantA, 'hr_document_templates.view');
        $templateB = HrDocumentTemplate::factory()->create(['tenant_id' => $tenantB->id]);

        $this->actingAs($user)->getJson($this->url($tenantA, "hr-document-templates/{$templateB->id}"))->assertNotFound();
    }

    public function test_user_cannot_update_template_from_another_tenant(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenantA, 'hr_document_templates.update');
        $templateB = HrDocumentTemplate::factory()->create(['tenant_id' => $tenantB->id, 'title' => 'Original']);

        $response = $this->actingAs($user)
            ->patchJson($this->url($tenantA, "hr-document-templates/{$templateB->id}"), ['title' => 'Hacked']);

        $response->assertNotFound();
        $this->assertSame('Original', $templateB->fresh()->title);
    }

    public function test_user_cannot_archive_template_from_another_tenant(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenantA, 'hr_document_templates.delete');
        $templateB = HrDocumentTemplate::factory()->create(['tenant_id' => $tenantB->id]);

        $this->actingAs($user)->deleteJson($this->url($tenantA, "hr-document-templates/{$templateB->id}"))->assertNotFound();
        $this->assertNull($templateB->fresh()->deleted_at);
    }

    public function test_request_body_tenant_id_cannot_force_cross_tenant_creation(): void
    {
        $tenant = Tenant::factory()->create();
        $otherTenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'hr_document_templates.create');

        $response = $this->actingAs($user)->postJson($this->url($tenant, 'hr-document-templates'), [
            'title' => 'Employment Letter',
            'document_type' => 'employment_letter',
            'content_template' => 'Dear {{employee.name}}',
            'tenant_id' => $otherTenant->id,
            'created_by' => 999,
            'updated_by' => 999,
        ]);

        $response->assertCreated();
        $this->assertDatabaseHas('hr_document_templates', ['title' => 'Employment Letter', 'tenant_id' => $tenant->id]);
        $this->assertDatabaseMissing('hr_document_templates', ['title' => 'Employment Letter', 'tenant_id' => $otherTenant->id]);
    }

    // 7: platform super admin blocked
    public function test_platform_super_admin_is_blocked_from_hr_document_template_api(): void
    {
        $tenant = Tenant::factory()->create();
        $admin = User::factory()->platformAdmin()->create();

        $response = $this->actingAs($admin)->getJson($this->url($tenant, 'hr-document-templates'));

        $response->assertForbidden();
    }

    // 8: resource safety
    public function test_hr_document_template_api_does_not_expose_internal_fields(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'hr_document_templates.view');
        HrDocumentTemplate::factory()->create(['tenant_id' => $tenant->id]);

        $response = $this->actingAs($user)->getJson($this->url($tenant, 'hr-document-templates'));
        $body = json_encode($response->json());

        foreach (['created_by', 'updated_by', 'deleted_at'] as $internalKey) {
            $this->assertStringNotContainsString($internalKey, $body, "Response unexpectedly contains '{$internalKey}'.");
        }
        $this->assertStringNotContainsString('"tenant_id"', $body);
    }

    // 17: audit logging
    public function test_create_template_writes_audit_log(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'hr_document_templates.create');

        $this->actingAs($user)->postJson($this->url($tenant, 'hr-document-templates'), [
            'title' => 'Employment Letter',
            'document_type' => 'employment_letter',
            'content_template' => 'Dear {{employee.name}}',
        ])->assertCreated();

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'hr_document_template.created',
            'module' => 'hr_documents',
            'actor_user_id' => $user->id,
            'tenant_id' => $tenant->id,
        ]);
    }

    public function test_update_template_writes_audit_log(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'hr_document_templates.update');
        $template = HrDocumentTemplate::factory()->create(['tenant_id' => $tenant->id, 'title' => 'Before']);

        $this->actingAs($user)
            ->patchJson($this->url($tenant, "hr-document-templates/{$template->id}"), ['title' => 'After'])
            ->assertOk();

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'hr_document_template.updated',
            'auditable_id' => $template->id,
        ]);
    }

    public function test_archive_template_writes_audit_log(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'hr_document_templates.delete');
        $template = HrDocumentTemplate::factory()->create(['tenant_id' => $tenant->id]);

        $this->actingAs($user)->deleteJson($this->url($tenant, "hr-document-templates/{$template->id}"))->assertOk();

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'hr_document_template.archived',
            'auditable_id' => $template->id,
        ]);
    }

    public function test_title_is_unique_within_tenant(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'hr_document_templates.create');
        HrDocumentTemplate::factory()->create(['tenant_id' => $tenant->id, 'title' => 'Employment Letter']);

        $response = $this->actingAs($user)->postJson($this->url($tenant, 'hr-document-templates'), [
            'title' => 'Employment Letter',
            'document_type' => 'employment_letter',
            'content_template' => 'Dear {{employee.name}}',
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('title');
    }

    public function test_inactive_user_fails_closed(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'hr_document_templates.view');
        $user->update(['status' => User::STATUS_INACTIVE]);

        $this->actingAs($user)->getJson($this->url($tenant, 'hr-document-templates'))->assertForbidden();
    }

    public function test_user_under_inactive_tenant_fails_closed(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'hr_document_templates.view');
        $tenant->update(['status' => Tenant::STATUS_SUSPENDED]);

        $this->actingAs($user)->getJson($this->url($tenant, 'hr-document-templates'))->assertForbidden();
    }
}
