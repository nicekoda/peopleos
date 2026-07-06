<?php

namespace Tests\Feature\HrDocuments;

use App\Models\Employee;
use App\Models\HrDocumentTemplate;
use App\Models\HrGeneratedDocument;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Checkpoint 34 — /settings/hr-document-templates(*) and /hr-documents(*).
 * Same shape as DocumentCategoryUiTest/LifecycleUiTest: permission
 * gating, guest redirects, tenant isolation, IDs-only props.
 */
class HrDocumentUiTest extends TestCase
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

    // 18: guest cannot access any HR document UI page
    public function test_guest_cannot_access_hr_document_ui(): void
    {
        $tenant = Tenant::factory()->create();
        $template = HrDocumentTemplate::factory()->create(['tenant_id' => $tenant->id]);
        $document = HrGeneratedDocument::factory()->create(['tenant_id' => $tenant->id]);

        foreach ([
            'settings/hr-document-templates',
            'settings/hr-document-templates/create',
            "settings/hr-document-templates/{$template->id}/edit",
            'hr-documents',
            'hr-documents/create',
            "hr-documents/{$document->id}",
        ] as $path) {
            $this->get($this->url($tenant, $path))->assertRedirect(route('login'));
        }
    }

    public function test_user_without_permission_cannot_access_template_pages(): void
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        $template = HrDocumentTemplate::factory()->create(['tenant_id' => $tenant->id]);

        $this->actingAs($user)->get($this->url($tenant, 'settings/hr-document-templates'))->assertForbidden();
        $this->actingAs($user)->get($this->url($tenant, 'settings/hr-document-templates/create'))->assertForbidden();
        $this->actingAs($user)->get($this->url($tenant, "settings/hr-document-templates/{$template->id}/edit"))->assertForbidden();
    }

    public function test_user_with_permission_can_access_template_pages(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions(
            $tenant,
            'hr_document_templates.view', 'hr_document_templates.create', 'hr_document_templates.update',
        );
        $template = HrDocumentTemplate::factory()->create(['tenant_id' => $tenant->id]);

        $this->actingAs($user)->get($this->url($tenant, 'settings/hr-document-templates'))
            ->assertOk()->assertInertia(fn ($page) => $page->component('Settings/HrDocumentTemplates/Index'));
        $this->actingAs($user)->get($this->url($tenant, 'settings/hr-document-templates/create'))
            ->assertOk()->assertInertia(fn ($page) => $page->component('Settings/HrDocumentTemplates/Create'));
        $this->actingAs($user)->get($this->url($tenant, "settings/hr-document-templates/{$template->id}/edit"))
            ->assertOk()->assertInertia(fn ($page) => $page->component('Settings/HrDocumentTemplates/Edit')->where('hrDocumentTemplateId', $template->id));
    }

    public function test_user_without_permission_cannot_access_hr_documents_pages(): void
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        $document = HrGeneratedDocument::factory()->create(['tenant_id' => $tenant->id]);

        $this->actingAs($user)->get($this->url($tenant, 'hr-documents'))->assertForbidden();
        $this->actingAs($user)->get($this->url($tenant, 'hr-documents/create'))->assertForbidden();
        $this->actingAs($user)->get($this->url($tenant, "hr-documents/{$document->id}"))->assertForbidden();
    }

    public function test_user_with_permission_can_access_hr_documents_pages(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'hr_generated_documents.view', 'hr_generated_documents.generate');
        $document = HrGeneratedDocument::factory()->create(['tenant_id' => $tenant->id]);

        $this->actingAs($user)->get($this->url($tenant, 'hr-documents'))
            ->assertOk()->assertInertia(fn ($page) => $page->component('HrDocuments/Index'));
        $this->actingAs($user)->get($this->url($tenant, 'hr-documents/create'))
            ->assertOk()->assertInertia(fn ($page) => $page->component('HrDocuments/Create'));
        $this->actingAs($user)->get($this->url($tenant, "hr-documents/{$document->id}"))
            ->assertOk()->assertInertia(fn ($page) => $page->component('HrDocuments/Show')->where('hrGeneratedDocumentId', $document->id));
    }

    // tenant isolation on UI routes
    public function test_cross_tenant_template_id_returns_404_on_edit_page(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();
        $userA = $this->userWithPermissions($tenantA, 'hr_document_templates.update');
        $templateB = HrDocumentTemplate::factory()->create(['tenant_id' => $tenantB->id]);

        $this->actingAs($userA)->get($this->url($tenantA, "settings/hr-document-templates/{$templateB->id}/edit"))->assertNotFound();
    }

    public function test_cross_tenant_document_id_returns_404_on_show_page(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();
        $userA = $this->userWithPermissions($tenantA, 'hr_generated_documents.view');
        $documentB = HrGeneratedDocument::factory()->create(['tenant_id' => $tenantB->id]);

        $this->actingAs($userA)->get($this->url($tenantA, "hr-documents/{$documentB->id}"))->assertNotFound();
    }

    // 19: props contain only IDs, no sensitive data
    public function test_edit_page_props_contain_only_template_id(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'hr_document_templates.update');
        $template = HrDocumentTemplate::factory()->create(['tenant_id' => $tenant->id, 'title' => 'Confidential Template Title']);

        $response = $this->actingAs($user)->get($this->url($tenant, "settings/hr-document-templates/{$template->id}/edit"));

        $page = $response->viewData('page');
        $this->assertSame(
            ['hrDocumentTemplateId'],
            array_keys(array_diff_key($page['props'], ['errors' => 1, 'auth' => 1, 'tenant' => 1])),
        );
        $this->assertStringNotContainsString('Confidential Template Title', json_encode($page['props']));
    }

    public function test_hr_document_show_page_props_contain_only_document_id(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'hr_generated_documents.view');
        $document = HrGeneratedDocument::factory()->create(['tenant_id' => $tenant->id, 'rendered_content' => 'CONFIDENTIAL-LETTER-BODY']);

        $response = $this->actingAs($user)->get($this->url($tenant, "hr-documents/{$document->id}"));

        $page = $response->viewData('page');
        $this->assertSame(
            ['hrGeneratedDocumentId'],
            array_keys(array_diff_key($page['props'], ['errors' => 1, 'auth' => 1, 'tenant' => 1])),
        );
        $this->assertStringNotContainsString('CONFIDENTIAL-LETTER-BODY', json_encode($page['props']));
    }

    // 20: employee detail link is permission-aware — Employees/Show.tsx
    // wraps the link in <PermissionGate permission="hr_generated_documents.view">,
    // and the destination route independently re-checks the identical
    // permission (test_user_without_permission_cannot_access_hr_documents_pages
    // above already proves the backend side of that). Verified here at
    // the permission-list level shared via HandleInertiaRequests.
    public function test_employee_show_page_permission_list_reflects_hr_generated_documents_view(): void
    {
        $tenant = Tenant::factory()->create();
        $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);
        $user = $this->userWithPermissions($tenant, 'employees.view', 'hr_generated_documents.view');

        $response = $this->actingAs($user)->get($this->url($tenant, "employees/{$employee->id}"));

        $response->assertOk();
        $page = $response->viewData('page');
        $this->assertContains('hr_generated_documents.view', $page['props']['auth']['user']['permissions']);
    }

    // Checkpoint 36 — HR Document Template Versioning Foundation UI.
    public function test_guest_cannot_access_template_version_ui(): void
    {
        $tenant = Tenant::factory()->create();
        $template = HrDocumentTemplate::factory()->create(['tenant_id' => $tenant->id]);
        $version = $template->currentVersion;

        $this->get($this->url($tenant, "settings/hr-document-templates/{$template->id}/versions/create"))
            ->assertRedirect(route('login'));
        $this->get($this->url($tenant, "settings/hr-document-template-versions/{$version->id}/edit"))
            ->assertRedirect(route('login'));
    }

    public function test_user_without_permission_cannot_access_template_version_ui(): void
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        $template = HrDocumentTemplate::factory()->create(['tenant_id' => $tenant->id]);
        $version = $template->currentVersion;

        $this->actingAs($user)->get($this->url($tenant, "settings/hr-document-templates/{$template->id}/versions/create"))->assertForbidden();
        $this->actingAs($user)->get($this->url($tenant, "settings/hr-document-template-versions/{$version->id}/edit"))->assertForbidden();
    }

    public function test_user_with_permission_can_access_template_version_ui(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'hr_document_templates.update');
        $template = HrDocumentTemplate::factory()->create(['tenant_id' => $tenant->id]);
        $version = $template->currentVersion;

        $this->actingAs($user)->get($this->url($tenant, "settings/hr-document-templates/{$template->id}/versions/create"))
            ->assertOk()->assertInertia(fn ($page) => $page->component('Settings/HrDocumentTemplates/VersionCreate')->where('hrDocumentTemplateId', $template->id));
        $this->actingAs($user)->get($this->url($tenant, "settings/hr-document-template-versions/{$version->id}/edit"))
            ->assertOk()->assertInertia(fn ($page) => $page->component('Settings/HrDocumentTemplates/VersionEdit')->where('hrDocumentTemplateVersionId', $version->id));
    }

    public function test_cross_tenant_version_id_returns_404_on_version_edit_page(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();
        $userA = $this->userWithPermissions($tenantA, 'hr_document_templates.update');
        $templateB = HrDocumentTemplate::factory()->create(['tenant_id' => $tenantB->id]);
        $versionB = $templateB->currentVersion;

        $this->actingAs($userA)->get($this->url($tenantA, "settings/hr-document-template-versions/{$versionB->id}/edit"))->assertNotFound();
    }

    public function test_version_edit_page_props_contain_only_version_id(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'hr_document_templates.update');
        $template = HrDocumentTemplate::factory()->create(['tenant_id' => $tenant->id]);
        $version = $template->currentVersion;
        $version->update(['content_template' => 'CONFIDENTIAL-VERSION-CONTENT']);

        $response = $this->actingAs($user)->get($this->url($tenant, "settings/hr-document-template-versions/{$version->id}/edit"));

        $page = $response->viewData('page');
        $this->assertSame(
            ['hrDocumentTemplateVersionId'],
            array_keys(array_diff_key($page['props'], ['errors' => 1, 'auth' => 1, 'tenant' => 1])),
        );
        $this->assertStringNotContainsString('CONFIDENTIAL-VERSION-CONTENT', json_encode($page['props']));
    }
}
