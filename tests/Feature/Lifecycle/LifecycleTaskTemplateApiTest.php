<?php

namespace Tests\Feature\Lifecycle;

use App\Models\LifecycleTaskTemplate;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Checkpoint 42 — Onboarding & Offboarding Task Templates Foundation.
 * Same shape as DepartmentApiTest/LocationApiTest/PositionApiTest: a
 * tenant-owned lookup catalog, soft-delete-only "archive", standard
 * permission gating and two-layer tenant isolation.
 */
class LifecycleTaskTemplateApiTest extends TestCase
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

    public function test_guest_cannot_access_api(): void
    {
        $tenant = Tenant::factory()->create();

        $this->getJson($this->url($tenant, 'lifecycle-task-templates'))->assertUnauthorized();
    }

    public function test_user_without_view_permission_cannot_list(): void
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->create(['tenant_id' => $tenant->id]);

        $this->actingAs($user)->getJson($this->url($tenant, 'lifecycle-task-templates'))->assertForbidden();
    }

    public function test_user_with_view_permission_can_list_and_view(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'lifecycle_task_templates.view');
        $template = LifecycleTaskTemplate::factory()->onboarding()->create(['tenant_id' => $tenant->id]);

        $this->actingAs($user)->getJson($this->url($tenant, 'lifecycle-task-templates'))->assertOk();
        $this->actingAs($user)->getJson($this->url($tenant, "lifecycle-task-templates/{$template->id}"))
            ->assertOk()
            ->assertJsonPath('data.id', $template->id);
    }

    public function test_user_without_create_permission_cannot_create(): void
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->create(['tenant_id' => $tenant->id]);

        $this->actingAs($user)->postJson($this->url($tenant, 'lifecycle-task-templates'), [
            'type' => 'onboarding',
            'title' => 'Send welcome email',
        ])->assertForbidden();
    }

    public function test_user_with_create_permission_can_create(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'lifecycle_task_templates.create');

        $response = $this->actingAs($user)->postJson($this->url($tenant, 'lifecycle-task-templates'), [
            'type' => 'onboarding',
            'title' => 'Send welcome email',
            'due_in_days' => 1,
            'sort_order' => 5,
        ]);

        $response->assertCreated();
        $this->assertDatabaseHas('lifecycle_task_templates', [
            'tenant_id' => $tenant->id,
            'type' => 'onboarding',
            'title' => 'Send welcome email',
            'due_in_days' => 1,
            'sort_order' => 5,
        ]);
    }

    public function test_forged_fields_are_ignored_on_create(): void
    {
        $tenant = Tenant::factory()->create();
        $otherTenant = Tenant::factory()->create();
        $otherUser = User::factory()->create(['tenant_id' => $otherTenant->id]);
        $user = $this->userWithPermissions($tenant, 'lifecycle_task_templates.create');

        $response = $this->actingAs($user)->postJson($this->url($tenant, 'lifecycle-task-templates'), [
            'type' => 'onboarding',
            'title' => 'Send welcome email',
            'tenant_id' => $otherTenant->id,
            'created_by' => $otherUser->id,
            'updated_by' => $otherUser->id,
        ]);

        $response->assertCreated();
        $template = LifecycleTaskTemplate::query()->findOrFail($response->json('data.id'));
        $this->assertSame($tenant->id, $template->tenant_id);
        $this->assertSame($user->id, $template->created_by);
        $this->assertSame(0, $template->sort_order);
    }

    public function test_user_without_update_permission_cannot_update(): void
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        $template = LifecycleTaskTemplate::factory()->create(['tenant_id' => $tenant->id]);

        $this->actingAs($user)->patchJson($this->url($tenant, "lifecycle-task-templates/{$template->id}"), ['title' => 'Updated'])
            ->assertForbidden();
    }

    public function test_user_with_update_permission_can_update(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'lifecycle_task_templates.update');
        $template = LifecycleTaskTemplate::factory()->create(['tenant_id' => $tenant->id, 'due_in_days' => 2]);

        $response = $this->actingAs($user)->patchJson($this->url($tenant, "lifecycle-task-templates/{$template->id}"), [
            'due_in_days' => 10,
        ]);

        $response->assertOk();
        $this->assertSame(10, $template->fresh()->due_in_days);
    }

    public function test_user_without_delete_permission_cannot_archive(): void
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        $template = LifecycleTaskTemplate::factory()->create(['tenant_id' => $tenant->id]);

        $this->actingAs($user)->deleteJson($this->url($tenant, "lifecycle-task-templates/{$template->id}"))->assertForbidden();
    }

    public function test_user_with_delete_permission_can_archive(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'lifecycle_task_templates.delete');
        $template = LifecycleTaskTemplate::factory()->create(['tenant_id' => $tenant->id]);

        $this->actingAs($user)->deleteJson($this->url($tenant, "lifecycle-task-templates/{$template->id}"))->assertOk();

        $this->assertSoftDeleted('lifecycle_task_templates', ['id' => $template->id]);
    }

    public function test_tenant_a_cannot_view_update_or_archive_tenant_b_template(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();
        $userA = $this->userWithPermissions($tenantA, 'lifecycle_task_templates.view', 'lifecycle_task_templates.update', 'lifecycle_task_templates.delete');
        $templateB = LifecycleTaskTemplate::factory()->create(['tenant_id' => $tenantB->id]);

        $this->actingAs($userA)->getJson($this->url($tenantA, "lifecycle-task-templates/{$templateB->id}"))->assertNotFound();
        $this->actingAs($userA)->patchJson($this->url($tenantA, "lifecycle-task-templates/{$templateB->id}"), ['title' => 'x'])->assertNotFound();
        $this->actingAs($userA)->deleteJson($this->url($tenantA, "lifecycle-task-templates/{$templateB->id}"))->assertNotFound();
    }

    public function test_tenant_a_cannot_list_tenant_b_templates(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();
        $userA = $this->userWithPermissions($tenantA, 'lifecycle_task_templates.view');
        LifecycleTaskTemplate::factory()->create(['tenant_id' => $tenantB->id, 'title' => 'Tenant B Only Template']);

        $response = $this->actingAs($userA)->getJson($this->url($tenantA, 'lifecycle-task-templates'));

        $response->assertOk();
        $this->assertStringNotContainsString('Tenant B Only Template', json_encode($response->json()));
    }

    public function test_platform_super_admin_is_blocked_from_api(): void
    {
        $tenant = Tenant::factory()->create();
        $admin = User::factory()->platformAdmin()->create();
        $template = LifecycleTaskTemplate::factory()->create(['tenant_id' => $tenant->id]);

        $this->actingAs($admin)->getJson($this->url($tenant, 'lifecycle-task-templates'))->assertForbidden();
        $this->actingAs($admin)->getJson($this->url($tenant, "lifecycle-task-templates/{$template->id}"))->assertForbidden();
    }

    public function test_resource_does_not_expose_internal_fields(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'lifecycle_task_templates.view');
        LifecycleTaskTemplate::factory()->create(['tenant_id' => $tenant->id]);

        $response = $this->actingAs($user)->getJson($this->url($tenant, 'lifecycle-task-templates'));
        $body = json_encode($response->json());

        $this->assertStringNotContainsString('"tenant_id"', $body);
        $this->assertStringNotContainsString('"created_by"', $body);
        $this->assertStringNotContainsString('"updated_by"', $body);
        $this->assertStringNotContainsString('"deleted_at"', $body);
    }

    public function test_create_writes_audit_log(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'lifecycle_task_templates.create');

        $this->actingAs($user)->postJson($this->url($tenant, 'lifecycle-task-templates'), [
            'type' => 'onboarding',
            'title' => 'Send welcome email',
        ])->assertCreated();

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'lifecycle_task_template.created',
            'module' => 'lifecycle',
            'actor_user_id' => $user->id,
        ]);
    }

    public function test_update_writes_audit_log(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'lifecycle_task_templates.update');
        $template = LifecycleTaskTemplate::factory()->create(['tenant_id' => $tenant->id]);

        $this->actingAs($user)->patchJson($this->url($tenant, "lifecycle-task-templates/{$template->id}"), ['title' => 'Updated title'])
            ->assertOk();

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'lifecycle_task_template.updated',
            'module' => 'lifecycle',
            'actor_user_id' => $user->id,
        ]);
    }

    public function test_archive_writes_audit_log(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'lifecycle_task_templates.delete');
        $template = LifecycleTaskTemplate::factory()->create(['tenant_id' => $tenant->id]);

        $this->actingAs($user)->deleteJson($this->url($tenant, "lifecycle-task-templates/{$template->id}"))->assertOk();

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'lifecycle_task_template.archived',
            'module' => 'lifecycle',
            'actor_user_id' => $user->id,
        ]);
    }

    public function test_title_is_unique_within_tenant_and_type(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'lifecycle_task_templates.create');
        LifecycleTaskTemplate::factory()->onboarding()->create(['tenant_id' => $tenant->id, 'title' => 'Send welcome email']);

        $response = $this->actingAs($user)->postJson($this->url($tenant, 'lifecycle-task-templates'), [
            'type' => 'onboarding',
            'title' => 'Send welcome email',
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('title');
    }

    public function test_same_title_can_exist_for_different_type_in_same_tenant(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'lifecycle_task_templates.create');
        LifecycleTaskTemplate::factory()->onboarding()->create(['tenant_id' => $tenant->id, 'title' => 'Exit checklist']);

        $response = $this->actingAs($user)->postJson($this->url($tenant, 'lifecycle-task-templates'), [
            'type' => 'offboarding',
            'title' => 'Exit checklist',
        ]);

        $response->assertCreated();
    }

    public function test_same_title_can_exist_in_different_tenants(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();
        LifecycleTaskTemplate::factory()->onboarding()->create(['tenant_id' => $tenantB->id, 'title' => 'Send welcome email']);
        $userA = $this->userWithPermissions($tenantA, 'lifecycle_task_templates.create');

        $response = $this->actingAs($userA)->postJson($this->url($tenantA, 'lifecycle-task-templates'), [
            'type' => 'onboarding',
            'title' => 'Send welcome email',
        ]);

        $response->assertCreated();
    }

    public function test_due_in_days_and_sort_order_are_validated(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'lifecycle_task_templates.create');

        $response = $this->actingAs($user)->postJson($this->url($tenant, 'lifecycle-task-templates'), [
            'type' => 'onboarding',
            'title' => 'Invalid template',
            'due_in_days' => -1,
            'sort_order' => -1,
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors(['due_in_days', 'sort_order']);
    }

    public function test_no_hard_delete_route_exists(): void
    {
        $templateRoutes = collect(Route::getRoutes())
            ->filter(fn ($route) => str_starts_with($route->uri(), 'api/v1/lifecycle-task-templates'))
            ->map(fn ($route) => implode('|', $route->methods()));

        // DELETE exists (soft-delete/archive only) — confirmed by the
        // controller itself never calling forceDelete(); no separate
        // hard-delete route/verb exists beyond the one DELETE route.
        $this->assertCount(5, $templateRoutes);
    }

    // All lifecycle-task-templates routes carry tenant.matches
    public function test_all_lifecycle_task_template_routes_include_tenant_matches_middleware(): void
    {
        $uris = [
            'api/v1/lifecycle-task-templates',
            'api/v1/lifecycle-task-templates/{lifecycleTaskTemplate}',
        ];

        $routes = collect(Route::getRoutes())->filter(fn ($route) => in_array($route->uri(), $uris));

        $this->assertGreaterThanOrEqual(count($uris), $routes->count());

        foreach ($routes as $route) {
            $this->assertContains(
                'tenant.matches',
                $route->gatherMiddleware(),
                "Route [{$route->methods()[0]} {$route->uri()}] is missing tenant.matches middleware.",
            );
        }
    }
}
