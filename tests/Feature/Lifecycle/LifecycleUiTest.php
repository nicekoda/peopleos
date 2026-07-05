<?php

namespace Tests\Feature\Lifecycle;

use App\Models\Employee;
use App\Models\LifecycleProcess;
use App\Models\LifecycleTask;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Checkpoint 33 — /lifecycle(/create)(/{id})(/{id}/edit)(/{id}/tasks/create)
 * (/tasks/{id}/edit). Same shape as every other module UI test —
 * permission gating, guest redirects, tenant isolation, and IDs-only
 * props for the edit/task pages.
 */
class LifecycleUiTest extends TestCase
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

    protected function linkedUser(Tenant $tenant, string ...$permissionKeys): array
    {
        $user = $this->userWithPermissions($tenant, ...$permissionKeys);
        $employee = Employee::factory()->create(['tenant_id' => $tenant->id, 'user_id' => $user->id]);

        return [$user, $employee];
    }

    protected function url(Tenant $tenant, string $path): string
    {
        return 'http://'.$tenant->subdomain.'.'.config('tenancy.base_domain').'/'.$path;
    }

    public function test_guest_cannot_access_lifecycle_ui(): void
    {
        $tenant = Tenant::factory()->create();
        $process = LifecycleProcess::factory()->create(['tenant_id' => $tenant->id]);
        $task = LifecycleTask::factory()->create(['tenant_id' => $tenant->id, 'process_id' => $process->id]);

        foreach ([
            'lifecycle',
            'lifecycle/create',
            "lifecycle/{$process->id}",
            "lifecycle/{$process->id}/edit",
            "lifecycle/{$process->id}/tasks/create",
            "lifecycle/tasks/{$task->id}/edit",
        ] as $path) {
            $this->get($this->url($tenant, $path))->assertRedirect(route('login'));
        }
    }

    public function test_user_without_view_cannot_access_index_page(): void
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->create(['tenant_id' => $tenant->id]);

        $this->actingAs($user)->get($this->url($tenant, 'lifecycle'))->assertForbidden();
    }

    public function test_user_with_view_can_access_index_page(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'lifecycle.view');

        $response = $this->actingAs($user)->get($this->url($tenant, 'lifecycle'));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page->component('Lifecycle/Index'));
    }

    public function test_user_without_create_cannot_access_create_page(): void
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->create(['tenant_id' => $tenant->id]);

        $this->actingAs($user)->get($this->url($tenant, 'lifecycle/create'))->assertForbidden();
    }

    public function test_user_with_create_can_access_create_page(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'lifecycle.create');

        $response = $this->actingAs($user)->get($this->url($tenant, 'lifecycle/create'));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page->component('Lifecycle/Create'));
    }

    public function test_user_with_view_can_access_show_page(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'lifecycle.view', 'lifecycle.create');
        $process = LifecycleProcess::factory()->create(['tenant_id' => $tenant->id]);

        $response = $this->actingAs($user)->get($this->url($tenant, "lifecycle/{$process->id}"));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page->component('Lifecycle/Show')->where('processId', $process->id));
    }

    public function test_line_manager_cannot_view_unrelated_processs_show_page(): void
    {
        $tenant = Tenant::factory()->create();
        [$manager] = $this->linkedUser($tenant, 'lifecycle.view', 'lifecycle.complete_task');
        $unrelated = Employee::factory()->create(['tenant_id' => $tenant->id]);
        $process = LifecycleProcess::factory()->create(['tenant_id' => $tenant->id, 'employee_id' => $unrelated->id]);

        $this->actingAs($manager)->get($this->url($tenant, "lifecycle/{$process->id}"))->assertNotFound();
    }

    public function test_user_without_update_cannot_access_edit_page(): void
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        $process = LifecycleProcess::factory()->create(['tenant_id' => $tenant->id]);

        $this->actingAs($user)->get($this->url($tenant, "lifecycle/{$process->id}/edit"))->assertForbidden();
    }

    public function test_user_with_update_can_access_edit_page(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'lifecycle.update');
        $process = LifecycleProcess::factory()->create(['tenant_id' => $tenant->id]);

        $response = $this->actingAs($user)->get($this->url($tenant, "lifecycle/{$process->id}/edit"));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page->component('Lifecycle/Edit')->where('processId', $process->id));
    }

    public function test_cross_tenant_process_id_returns_404_on_show_and_edit_pages(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();
        $userA = $this->userWithPermissions($tenantA, 'lifecycle.view', 'lifecycle.update');
        $processB = LifecycleProcess::factory()->create(['tenant_id' => $tenantB->id]);

        $this->actingAs($userA)->get($this->url($tenantA, "lifecycle/{$processB->id}"))->assertNotFound();
        $this->actingAs($userA)->get($this->url($tenantA, "lifecycle/{$processB->id}/edit"))->assertNotFound();
    }

    public function test_user_with_create_can_access_task_create_page(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'lifecycle.create');
        $process = LifecycleProcess::factory()->create(['tenant_id' => $tenant->id]);

        $response = $this->actingAs($user)->get($this->url($tenant, "lifecycle/{$process->id}/tasks/create"));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page->component('Lifecycle/TaskCreate')->where('processId', $process->id));
    }

    public function test_user_without_update_cannot_access_task_edit_page(): void
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        $process = LifecycleProcess::factory()->create(['tenant_id' => $tenant->id]);
        $task = LifecycleTask::factory()->create(['tenant_id' => $tenant->id, 'process_id' => $process->id]);

        $this->actingAs($user)->get($this->url($tenant, "lifecycle/tasks/{$task->id}/edit"))->assertForbidden();
    }

    public function test_user_with_update_can_access_task_edit_page(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'lifecycle.update');
        $process = LifecycleProcess::factory()->create(['tenant_id' => $tenant->id]);
        $task = LifecycleTask::factory()->create(['tenant_id' => $tenant->id, 'process_id' => $process->id]);

        $response = $this->actingAs($user)->get($this->url($tenant, "lifecycle/tasks/{$task->id}/edit"));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page->component('Lifecycle/TaskEdit')
            ->where('taskId', $task->id)
            ->where('processId', $process->id));
    }

    public function test_cross_tenant_task_id_returns_404_on_task_edit_page(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();
        $userA = $this->userWithPermissions($tenantA, 'lifecycle.update');
        $processB = LifecycleProcess::factory()->create(['tenant_id' => $tenantB->id]);
        $taskB = LifecycleTask::factory()->create(['tenant_id' => $tenantB->id, 'process_id' => $processB->id]);

        $this->actingAs($userA)->get($this->url($tenantA, "lifecycle/tasks/{$taskB->id}/edit"))->assertNotFound();
    }

    public function test_edit_page_props_contain_only_process_id(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'lifecycle.update');
        $employee = Employee::factory()->create(['tenant_id' => $tenant->id, 'first_name' => 'Confidential', 'last_name' => 'Employee']);
        $process = LifecycleProcess::factory()->create(['tenant_id' => $tenant->id, 'employee_id' => $employee->id]);

        $response = $this->actingAs($user)->get($this->url($tenant, "lifecycle/{$process->id}/edit"));

        $page = $response->viewData('page');
        $this->assertSame(
            ['processId'],
            array_keys(array_diff_key($page['props'], ['errors' => 1, 'auth' => 1, 'tenant' => 1])),
        );
        $this->assertStringNotContainsString('Confidential', json_encode($page['props']));
    }

    public function test_index_page_props_contain_no_ids(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'lifecycle.view');

        $response = $this->actingAs($user)->get($this->url($tenant, 'lifecycle'));

        $page = $response->viewData('page');
        $this->assertSame([], array_keys(array_diff_key($page['props'], ['errors' => 1, 'auth' => 1, 'tenant' => 1])));
    }
}
