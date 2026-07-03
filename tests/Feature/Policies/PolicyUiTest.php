<?php

namespace Tests\Feature\Policies;

use App\Models\Permission;
use App\Models\Policy;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Backend-testable surface of Checkpoint 20's Policy Management UI —
 * same shape as EmployeeUiTest/LeaveUiTest/EmployeeDocumentUiTest.
 * Policy/version/acknowledgement data is fetched client-side from the
 * existing, already-tested /api/v1/policies endpoints (Checkpoint 10)
 * plus the new read-only /api/v1/policies/{policy}/versions endpoint
 * (Checkpoint 20, tested separately in PolicyApiTest) — these tests
 * cover only the web route layer: permission gating, guest redirects,
 * tenant isolation, and the safe policyId-only props. Publish
 * confirmation, the draft-version selector, the employee multi-select,
 * the acknowledge button, and client-side error banners are not
 * server-testable — verified via tsc --noEmit, npm run build, and a
 * live HTTPS smoke test. See docs/testing.md.
 */
class PolicyUiTest extends TestCase
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

    protected function routesUnderTest(string $policyId): array
    {
        return [
            'policies',
            'policies/create',
            "policies/{$policyId}",
            "policies/{$policyId}/edit",
            "policies/{$policyId}/versions/create",
            "policies/{$policyId}/assign",
            "policies/{$policyId}/acknowledgements",
        ];
    }

    // 1: guest cannot access any policy UI page
    public function test_guest_cannot_access_policy_ui_pages(): void
    {
        $tenant = Tenant::factory()->create();
        $policy = Policy::factory()->create(['tenant_id' => $tenant->id]);

        foreach ($this->routesUnderTest($policy->id) as $path) {
            $this->get($this->url($tenant, $path))->assertRedirect(route('login'));
        }
    }

    // 2/3: list page permission gating
    public function test_user_without_policies_view_cannot_access_list_page(): void
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->create(['tenant_id' => $tenant->id]);

        $this->actingAs($user)->get($this->url($tenant, 'policies'))->assertForbidden();
    }

    public function test_user_with_policies_view_can_access_list_page(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'policies.view');

        $response = $this->actingAs($user)->get($this->url($tenant, 'policies'));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page->component('Policies/Index'));
    }

    // 2/3: detail page permission gating
    public function test_user_without_policies_view_cannot_access_detail_page(): void
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        $policy = Policy::factory()->create(['tenant_id' => $tenant->id]);

        $this->actingAs($user)->get($this->url($tenant, "policies/{$policy->id}"))->assertForbidden();
    }

    public function test_user_with_policies_view_can_access_detail_page(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'policies.view');
        $policy = Policy::factory()->create(['tenant_id' => $tenant->id]);

        $response = $this->actingAs($user)->get($this->url($tenant, "policies/{$policy->id}"));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page->component('Policies/Show')->where('policyId', $policy->id));
    }

    // 4/5: create page permission gating
    public function test_user_without_policies_create_cannot_access_create_page(): void
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->create(['tenant_id' => $tenant->id]);

        $this->actingAs($user)->get($this->url($tenant, 'policies/create'))->assertForbidden();
    }

    public function test_user_with_policies_create_can_access_create_page(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'policies.create');

        $response = $this->actingAs($user)->get($this->url($tenant, 'policies/create'));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page->component('Policies/Create'));
    }

    // 6/7: edit page permission gating
    public function test_user_without_policies_update_cannot_access_edit_page(): void
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        $policy = Policy::factory()->create(['tenant_id' => $tenant->id]);

        $this->actingAs($user)->get($this->url($tenant, "policies/{$policy->id}/edit"))->assertForbidden();
    }

    public function test_user_with_policies_update_can_access_edit_page(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'policies.update');
        $policy = Policy::factory()->create(['tenant_id' => $tenant->id]);

        $response = $this->actingAs($user)->get($this->url($tenant, "policies/{$policy->id}/edit"));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page->component('Policies/Edit')->where('policyId', $policy->id));
    }

    // 8/9: version creation page permission gating
    public function test_user_without_policies_update_cannot_access_version_create_page(): void
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        $policy = Policy::factory()->create(['tenant_id' => $tenant->id]);

        $this->actingAs($user)->get($this->url($tenant, "policies/{$policy->id}/versions/create"))->assertForbidden();
    }

    public function test_user_with_policies_update_can_access_version_create_page(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'policies.update');
        $policy = Policy::factory()->create(['tenant_id' => $tenant->id]);

        $response = $this->actingAs($user)->get($this->url($tenant, "policies/{$policy->id}/versions/create"));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page->component('Policies/VersionCreate')->where('policyId', $policy->id));
    }

    // 10/11: assign page permission gating
    public function test_user_without_policies_assign_cannot_access_assign_page(): void
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        $policy = Policy::factory()->create(['tenant_id' => $tenant->id]);

        $this->actingAs($user)->get($this->url($tenant, "policies/{$policy->id}/assign"))->assertForbidden();
    }

    public function test_user_with_policies_assign_can_access_assign_page(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'policies.assign');
        $policy = Policy::factory()->create(['tenant_id' => $tenant->id]);

        $response = $this->actingAs($user)->get($this->url($tenant, "policies/{$policy->id}/assign"));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page->component('Policies/Assign')->where('policyId', $policy->id));
    }

    // 12/13: acknowledgements page permission gating
    public function test_user_without_view_acknowledgements_cannot_access_acknowledgements_page(): void
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        $policy = Policy::factory()->create(['tenant_id' => $tenant->id]);

        $this->actingAs($user)->get($this->url($tenant, "policies/{$policy->id}/acknowledgements"))->assertForbidden();
    }

    public function test_user_with_view_acknowledgements_can_access_acknowledgements_page(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'policies.view_acknowledgements');
        $policy = Policy::factory()->create(['tenant_id' => $tenant->id]);

        $response = $this->actingAs($user)->get($this->url($tenant, "policies/{$policy->id}/acknowledgements"));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page->component('Policies/Acknowledgements')->where('policyId', $policy->id));
    }

    // 14: cross-tenant policy ID returns safe 404 on every {policy}-bound route
    public function test_cross_tenant_policy_id_returns_404_on_every_bound_route(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();
        $userA = $this->userWithPermissions(
            $tenantA,
            'policies.view', 'policies.update', 'policies.assign', 'policies.view_acknowledgements',
        );
        $policyB = Policy::factory()->create(['tenant_id' => $tenantB->id]);

        foreach ([
            "policies/{$policyB->id}",
            "policies/{$policyB->id}/edit",
            "policies/{$policyB->id}/versions/create",
            "policies/{$policyB->id}/assign",
            "policies/{$policyB->id}/acknowledgements",
        ] as $path) {
            $this->actingAs($userA)->get($this->url($tenantA, $path))->assertNotFound();
        }
    }

    // 15: shared Inertia props for every {policy}-bound page carry only policyId, never policy data
    public function test_bound_pages_props_contain_only_policy_id(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions(
            $tenant,
            'policies.view', 'policies.update', 'policies.assign', 'policies.view_acknowledgements',
        );
        $policy = Policy::factory()->create([
            'tenant_id' => $tenant->id,
            'title' => 'Confidential Disciplinary Procedure',
        ]);

        foreach ([
            "policies/{$policy->id}",
            "policies/{$policy->id}/edit",
            "policies/{$policy->id}/versions/create",
            "policies/{$policy->id}/assign",
            "policies/{$policy->id}/acknowledgements",
        ] as $path) {
            $response = $this->actingAs($user)->get($this->url($tenant, $path));
            $page = $response->viewData('page');

            $this->assertSame(
                ['policyId'],
                array_keys(array_diff_key($page['props'], ['errors' => 1, 'auth' => 1, 'tenant' => 1])),
                "Route [{$path}] passed unexpected props.",
            );
            $this->assertStringNotContainsString('Confidential Disciplinary Procedure', json_encode($page['props']));
        }
    }

    public function test_index_and_create_pages_props_contain_no_ids(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'policies.view', 'policies.create');

        foreach (['policies', 'policies/create'] as $path) {
            $response = $this->actingAs($user)->get($this->url($tenant, $path));
            $page = $response->viewData('page');

            $this->assertSame(
                [],
                array_keys(array_diff_key($page['props'], ['errors' => 1, 'auth' => 1, 'tenant' => 1])),
                "Route [{$path}] passed unexpected props.",
            );
        }
    }
}
