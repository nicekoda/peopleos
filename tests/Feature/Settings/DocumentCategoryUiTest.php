<?php

namespace Tests\Feature\Settings;

use App\Models\DocumentCategory;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Checkpoint 25 — /settings/document-categories(/create)(/{id}/edit).
 * Same shape as every other module UI test — permission gating, guest
 * redirects, tenant isolation, and IDs-only props for the edit page.
 * DocumentCategory already uses BelongsToTenant (unlike User/Role/
 * AuditLog in Checkpoints 23/24), so this is the standard two-layer
 * pattern.
 */
class DocumentCategoryUiTest extends TestCase
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

    // 1: guest cannot access any page
    public function test_guest_cannot_access_document_categories_ui(): void
    {
        $tenant = Tenant::factory()->create();
        $category = DocumentCategory::factory()->create(['tenant_id' => $tenant->id]);

        foreach ([
            'settings/document-categories',
            'settings/document-categories/create',
            "settings/document-categories/{$category->id}/edit",
        ] as $path) {
            $this->get($this->url($tenant, $path))->assertRedirect(route('login'));
        }
    }

    // 2/3: list page permission gating
    public function test_user_without_view_cannot_access_list_page(): void
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->create(['tenant_id' => $tenant->id]);

        $this->actingAs($user)->get($this->url($tenant, 'settings/document-categories'))->assertForbidden();
    }

    public function test_user_with_view_can_access_list_page(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'document_categories.view');

        $response = $this->actingAs($user)->get($this->url($tenant, 'settings/document-categories'));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page->component('Settings/DocumentCategories/Index'));
    }

    // 4: create page permission gating
    public function test_user_without_create_cannot_access_create_page(): void
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->create(['tenant_id' => $tenant->id]);

        $this->actingAs($user)->get($this->url($tenant, 'settings/document-categories/create'))->assertForbidden();
    }

    public function test_user_with_create_can_access_create_page(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'document_categories.create');

        $response = $this->actingAs($user)->get($this->url($tenant, 'settings/document-categories/create'));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page->component('Settings/DocumentCategories/Create'));
    }

    // 5: edit page permission gating
    public function test_user_without_update_cannot_access_edit_page(): void
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        $category = DocumentCategory::factory()->create(['tenant_id' => $tenant->id]);

        $this->actingAs($user)->get($this->url($tenant, "settings/document-categories/{$category->id}/edit"))->assertForbidden();
    }

    public function test_user_with_update_can_access_edit_page(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'document_categories.update');
        $category = DocumentCategory::factory()->create(['tenant_id' => $tenant->id]);

        $response = $this->actingAs($user)->get($this->url($tenant, "settings/document-categories/{$category->id}/edit"));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page->component('Settings/DocumentCategories/Edit')->where('documentCategoryId', $category->id));
    }

    // 6: delete/archive gating is enforced by the existing API test suite
    // (DocumentCategoryApiTest, Checkpoint 9) — this UI only shows/hides
    // the button; re-asserted here at the permission level via the
    // shared PermissionGate pattern, not duplicated as a new API test.
    public function test_user_without_delete_permission_cannot_archive_via_api(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'document_categories.view');
        $category = DocumentCategory::factory()->create(['tenant_id' => $tenant->id]);

        $this->actingAs($user)->deleteJson(
            'http://'.$tenant->subdomain.'.'.config('tenancy.base_domain')."/api/v1/document-categories/{$category->id}"
        )->assertForbidden();
    }

    // 7: tenant isolation
    public function test_cross_tenant_document_category_id_returns_404_on_edit_page(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();
        $userA = $this->userWithPermissions($tenantA, 'document_categories.update');
        $categoryB = DocumentCategory::factory()->create(['tenant_id' => $tenantB->id]);

        $this->actingAs($userA)->get($this->url($tenantA, "settings/document-categories/{$categoryB->id}/edit"))->assertNotFound();
    }

    // 8: props contain only the ID
    public function test_edit_page_props_contain_only_document_category_id(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'document_categories.update');
        $category = DocumentCategory::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Confidential Category Name']);

        $response = $this->actingAs($user)->get($this->url($tenant, "settings/document-categories/{$category->id}/edit"));

        $page = $response->viewData('page');
        $this->assertSame(
            ['documentCategoryId'],
            array_keys(array_diff_key($page['props'], ['errors' => 1, 'auth' => 1, 'tenant' => 1])),
        );
        $this->assertStringNotContainsString('Confidential Category Name', json_encode($page['props']));
    }

    public function test_list_page_props_contain_no_ids(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'document_categories.view');

        $response = $this->actingAs($user)->get($this->url($tenant, 'settings/document-categories'));

        $page = $response->viewData('page');
        $this->assertSame([], array_keys(array_diff_key($page['props'], ['errors' => 1, 'auth' => 1, 'tenant' => 1])));
    }

    // 9: API does not expose internal fields
    public function test_document_category_api_does_not_expose_internal_fields(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'document_categories.view');
        DocumentCategory::factory()->create(['tenant_id' => $tenant->id]);

        $response = $this->actingAs($user)->getJson(
            'http://'.$tenant->subdomain.'.'.config('tenancy.base_domain').'/api/v1/document-categories'
        );
        $body = json_encode($response->json());

        foreach (['created_by', 'updated_by', 'deleted_at'] as $internalKey) {
            $this->assertStringNotContainsString($internalKey, $body, "Response unexpectedly contains '{$internalKey}'.");
        }
        // tenant_id is only ever asserted absent from the *response*, not the
        // database — the row itself must always have a real tenant_id.
        $this->assertStringNotContainsString('"tenant_id"', $body);
    }
}
