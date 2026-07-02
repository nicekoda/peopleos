<?php

namespace Tests\Feature\Policies;

use App\Enums\PolicyStatus;
use App\Models\Employee;
use App\Models\EmployeeDocument;
use App\Models\Permission;
use App\Models\Policy;
use App\Models\PolicyAcknowledgement;
use App\Models\PolicyVersion;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PolicyApiTest extends TestCase
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

    /**
     * Creates a published policy with one version, ready to assign.
     */
    protected function publishedPolicy(Tenant $tenant, User $actor): Policy
    {
        $policy = Policy::factory()->create(['tenant_id' => $tenant->id]);
        $version = PolicyVersion::factory()->create([
            'tenant_id' => $tenant->id,
            'policy_id' => $policy->id,
            'version_number' => 1,
            'status' => PolicyStatus::Published,
            'published_by' => $actor->id,
            'published_at' => now(),
        ]);
        $policy->update(['status' => PolicyStatus::Published, 'current_version_id' => $version->id]);

        return $policy->fresh();
    }

    public function test_user_with_permission_can_create_policy(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'policies.create');

        $response = $this->actingAs($user)->postJson($this->url($tenant, 'policies'), [
            'title' => 'Code of Conduct',
        ]);

        $response->assertCreated();
        $this->assertDatabaseHas('policies', ['title' => 'Code of Conduct', 'tenant_id' => $tenant->id]);
    }

    public function test_user_without_permission_cannot_create_policy(): void
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->create(['tenant_id' => $tenant->id]);

        $response = $this->actingAs($user)->postJson($this->url($tenant, 'policies'), [
            'title' => 'Code of Conduct',
        ]);

        $response->assertForbidden();
    }

    public function test_user_with_permission_can_list_own_tenant_policies(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'policies.view');
        Policy::factory()->count(2)->create(['tenant_id' => $tenant->id]);

        $response = $this->actingAs($user)->getJson($this->url($tenant, 'policies'));

        $response->assertOk();
        $this->assertCount(2, $response->json('data'));
    }

    public function test_user_without_view_permission_cannot_list_policies(): void
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->create(['tenant_id' => $tenant->id]);

        $response = $this->actingAs($user)->getJson($this->url($tenant, 'policies'));

        $response->assertForbidden();
    }

    public function test_tenant_a_cannot_list_tenant_b_policies(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenantA, 'policies.view');
        Policy::factory()->create(['tenant_id' => $tenantA->id, 'title' => 'A Policy']);
        Policy::factory()->create(['tenant_id' => $tenantB->id, 'title' => 'B Policy']);

        $response = $this->actingAs($user)->getJson($this->url($tenantA, 'policies'));

        $response->assertOk();
        $titles = collect($response->json('data'))->pluck('title');
        $this->assertTrue($titles->contains('A Policy'));
        $this->assertFalse($titles->contains('B Policy'));
    }

    public function test_tenant_a_cannot_view_tenant_b_policy_by_guessed_id(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenantA, 'policies.view');
        $policyB = Policy::factory()->create(['tenant_id' => $tenantB->id]);

        $response = $this->actingAs($user)->getJson($this->url($tenantA, "policies/{$policyB->id}"));

        $response->assertNotFound();
    }

    public function test_tenant_a_cannot_update_tenant_b_policy(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenantA, 'policies.update');
        $policyB = Policy::factory()->create(['tenant_id' => $tenantB->id, 'title' => 'Original']);

        $response = $this->actingAs($user)
            ->patchJson($this->url($tenantA, "policies/{$policyB->id}"), ['title' => 'Hacked']);

        $response->assertNotFound();
        $this->assertSame('Original', $policyB->fresh()->title);
    }

    public function test_tenant_a_cannot_publish_tenant_b_policy(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenantA, 'policies.publish');
        $policyB = Policy::factory()->create(['tenant_id' => $tenantB->id]);
        $versionB = PolicyVersion::factory()->create([
            'tenant_id' => $tenantB->id, 'policy_id' => $policyB->id, 'content' => 'Some content',
        ]);

        $response = $this->actingAs($user)
            ->postJson($this->url($tenantA, "policies/{$policyB->id}/publish"), ['policy_version_id' => $versionB->id]);

        $response->assertNotFound();
    }

    public function test_tenant_a_cannot_view_tenant_b_acknowledgements(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenantA, 'policies.view_acknowledgements');
        $policyB = Policy::factory()->create(['tenant_id' => $tenantB->id]);

        $response = $this->actingAs($user)->getJson($this->url($tenantA, "policies/{$policyB->id}/acknowledgements"));

        $response->assertNotFound();
    }

    public function test_request_body_tenant_id_cannot_force_cross_tenant_policy_creation(): void
    {
        $tenant = Tenant::factory()->create();
        $otherTenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'policies.create');

        $response = $this->actingAs($user)->postJson($this->url($tenant, 'policies'), [
            'title' => 'Code of Conduct',
            'tenant_id' => $otherTenant->id,
        ]);

        $response->assertCreated();
        $this->assertDatabaseHas('policies', ['title' => 'Code of Conduct', 'tenant_id' => $tenant->id]);
        $this->assertDatabaseMissing('policies', ['title' => 'Code of Conduct', 'tenant_id' => $otherTenant->id]);
    }

    public function test_policy_title_uniqueness_is_tenant_scoped(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();
        Policy::factory()->create(['tenant_id' => $tenantA->id, 'title' => 'Code of Conduct']);

        $userA = $this->userWithPermissions($tenantA, 'policies.create');
        $this->actingAs($userA)->postJson($this->url($tenantA, 'policies'), ['title' => 'Code of Conduct'])
            ->assertStatus(422)->assertJsonValidationErrors('title');

        $userB = $this->userWithPermissions($tenantB, 'policies.create');
        $this->actingAs($userB)->postJson($this->url($tenantB, 'policies'), ['title' => 'Code of Conduct'])
            ->assertCreated();
    }

    public function test_policy_version_can_be_created(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'policies.update');
        $policy = Policy::factory()->create(['tenant_id' => $tenant->id]);

        $response = $this->actingAs($user)->postJson($this->url($tenant, "policies/{$policy->id}/versions"), [
            'title' => 'v1',
            'content' => 'Policy text here.',
        ]);

        $response->assertCreated();
        $this->assertDatabaseHas('policy_versions', ['policy_id' => $policy->id, 'version_number' => 1]);
    }

    public function test_policy_version_cannot_attach_document_from_another_tenant(): void
    {
        Storage::fake('local');

        $tenant = Tenant::factory()->create();
        $otherTenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'policies.update');
        $policy = Policy::factory()->create(['tenant_id' => $tenant->id]);
        $foreignEmployee = Employee::factory()->create(['tenant_id' => $otherTenant->id]);
        $foreignDocument = EmployeeDocument::factory()->create([
            'tenant_id' => $otherTenant->id,
            'employee_id' => $foreignEmployee->id,
        ]);

        $response = $this->actingAs($user)->postJson($this->url($tenant, "policies/{$policy->id}/versions"), [
            'title' => 'v1',
            'employee_document_id' => $foreignDocument->id,
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('employee_document_id');
    }

    public function test_policy_can_be_published(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'policies.publish');
        $policy = Policy::factory()->create(['tenant_id' => $tenant->id]);
        $version = PolicyVersion::factory()->create([
            'tenant_id' => $tenant->id, 'policy_id' => $policy->id, 'content' => 'Some content',
        ]);

        $response = $this->actingAs($user)
            ->postJson($this->url($tenant, "policies/{$policy->id}/publish"), ['policy_version_id' => $version->id]);

        $response->assertOk();
        $this->assertSame(PolicyStatus::Published->value, $policy->fresh()->status->value);
        $this->assertSame($version->id, $policy->fresh()->current_version_id);
        $this->assertSame(PolicyStatus::Published->value, $version->fresh()->status->value);
        $this->assertNotNull($version->fresh()->published_at);
    }

    public function test_old_published_version_is_archived_not_deleted_on_republish(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'policies.publish');
        $policy = $this->publishedPolicy($tenant, $user);
        $oldVersionId = $policy->current_version_id;

        $newVersion = PolicyVersion::factory()->create([
            'tenant_id' => $tenant->id, 'policy_id' => $policy->id, 'version_number' => 2, 'content' => 'v2 content',
        ]);

        $this->actingAs($user)
            ->postJson($this->url($tenant, "policies/{$policy->id}/publish"), ['policy_version_id' => $newVersion->id])
            ->assertOk();

        $this->assertDatabaseHas('policy_versions', ['id' => $oldVersionId, 'status' => PolicyStatus::Archived->value]);
        $this->assertNotSoftDeleted('policy_versions', ['id' => $oldVersionId]);
        $this->assertSame($newVersion->id, $policy->fresh()->current_version_id);
    }

    public function test_publishing_writes_audit_log(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'policies.publish');
        $policy = Policy::factory()->create(['tenant_id' => $tenant->id]);
        $version = PolicyVersion::factory()->create([
            'tenant_id' => $tenant->id, 'policy_id' => $policy->id, 'content' => 'Some content',
        ]);

        $this->actingAs($user)
            ->postJson($this->url($tenant, "policies/{$policy->id}/publish"), ['policy_version_id' => $version->id])
            ->assertOk();

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'policy.published',
            'module' => 'policies',
            'actor_user_id' => $user->id,
            'auditable_id' => $policy->id,
        ]);
    }

    public function test_policy_can_be_assigned_to_employee_in_same_tenant(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'policies.assign');
        $policy = $this->publishedPolicy($tenant, $user);
        $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);

        $response = $this->actingAs($user)
            ->postJson($this->url($tenant, "policies/{$policy->id}/assign"), ['employee_ids' => [$employee->id]]);

        $response->assertCreated();
        $this->assertDatabaseHas('policy_acknowledgements', [
            'policy_id' => $policy->id,
            'employee_id' => $employee->id,
            'acknowledgement_status' => 'pending',
        ]);
    }

    public function test_policy_cannot_be_assigned_to_employee_from_another_tenant(): void
    {
        $tenant = Tenant::factory()->create();
        $otherTenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'policies.assign');
        $policy = $this->publishedPolicy($tenant, $user);
        $foreignEmployee = Employee::factory()->create(['tenant_id' => $otherTenant->id]);

        $response = $this->actingAs($user)
            ->postJson($this->url($tenant, "policies/{$policy->id}/assign"), ['employee_ids' => [$foreignEmployee->id]]);

        $response->assertStatus(422)->assertJsonValidationErrors('employee_ids.0');
    }

    public function test_duplicate_assignment_for_same_employee_and_version_is_prevented(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'policies.assign');
        $policy = $this->publishedPolicy($tenant, $user);
        $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);

        $this->actingAs($user)
            ->postJson($this->url($tenant, "policies/{$policy->id}/assign"), ['employee_ids' => [$employee->id]])
            ->assertCreated();

        $response = $this->actingAs($user)
            ->postJson($this->url($tenant, "policies/{$policy->id}/assign"), ['employee_ids' => [$employee->id]]);

        $response->assertCreated();
        $this->assertSame([$employee->id], $response->json('skipped_duplicates'));
        $this->assertSame(1, PolicyAcknowledgement::query()->where('employee_id', $employee->id)->count());
    }

    public function test_user_with_permission_can_acknowledge_assigned_policy(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'policies.assign', 'policies.acknowledge');
        $policy = $this->publishedPolicy($tenant, $user);
        $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);
        $this->actingAs($user)->postJson($this->url($tenant, "policies/{$policy->id}/assign"), ['employee_ids' => [$employee->id]]);

        $response = $this->actingAs($user)
            ->postJson($this->url($tenant, "policies/{$policy->id}/acknowledge"), ['employee_id' => $employee->id]);

        $response->assertOk();
        $this->assertDatabaseHas('policy_acknowledgements', [
            'policy_id' => $policy->id,
            'employee_id' => $employee->id,
            'acknowledgement_status' => 'acknowledged',
            'acknowledgement_method' => 'admin_recorded',
        ]);
    }

    public function test_user_cannot_acknowledge_unassigned_policy(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'policies.acknowledge');
        $policy = $this->publishedPolicy($tenant, $user);
        $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);

        $response = $this->actingAs($user)
            ->postJson($this->url($tenant, "policies/{$policy->id}/acknowledge"), ['employee_id' => $employee->id]);

        $response->assertNotFound();
    }

    public function test_user_cannot_acknowledge_another_tenants_policy(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();
        $userA = $this->userWithPermissions($tenantA, 'policies.acknowledge');
        $policyB = Policy::factory()->create(['tenant_id' => $tenantB->id]);
        $employeeA = Employee::factory()->create(['tenant_id' => $tenantA->id]);

        $response = $this->actingAs($userA)
            ->postJson($this->url($tenantA, "policies/{$policyB->id}/acknowledge"), ['employee_id' => $employeeA->id]);

        $response->assertNotFound();
    }

    public function test_user_without_acknowledge_permission_cannot_acknowledge(): void
    {
        $tenant = Tenant::factory()->create();
        $assigner = $this->userWithPermissions($tenant, 'policies.assign');
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        $policy = $this->publishedPolicy($tenant, $assigner);
        $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);
        $this->actingAs($assigner)->postJson($this->url($tenant, "policies/{$policy->id}/assign"), ['employee_ids' => [$employee->id]]);

        $response = $this->actingAs($user)
            ->postJson($this->url($tenant, "policies/{$policy->id}/acknowledge"), ['employee_id' => $employee->id]);

        $response->assertForbidden();
    }

    public function test_user_without_view_acknowledgements_permission_cannot_view_records(): void
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        $policy = Policy::factory()->create(['tenant_id' => $tenant->id]);

        $response = $this->actingAs($user)->getJson($this->url($tenant, "policies/{$policy->id}/acknowledgements"));

        $response->assertForbidden();
    }

    public function test_acknowledgement_writes_audit_log(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'policies.assign', 'policies.acknowledge');
        $policy = $this->publishedPolicy($tenant, $user);
        $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);
        $this->actingAs($user)->postJson($this->url($tenant, "policies/{$policy->id}/assign"), ['employee_ids' => [$employee->id]]);

        $this->actingAs($user)
            ->postJson($this->url($tenant, "policies/{$policy->id}/acknowledge"), ['employee_id' => $employee->id])
            ->assertOk();

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'policy.acknowledged',
            'module' => 'policies',
            'actor_user_id' => $user->id,
        ]);
    }

    public function test_create_update_and_assign_actions_write_audit_logs(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'policies.create', 'policies.update', 'policies.assign', 'policies.publish');

        $created = $this->actingAs($user)->postJson($this->url($tenant, 'policies'), ['title' => 'Data Protection Policy'])->json('data');
        $this->assertDatabaseHas('audit_logs', ['action' => 'policy.created', 'auditable_id' => $created['id']]);

        $this->actingAs($user)->patchJson($this->url($tenant, "policies/{$created['id']}"), ['description' => 'Updated.']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'policy.updated', 'auditable_id' => $created['id']]);

        $version = PolicyVersion::factory()->create(['tenant_id' => $tenant->id, 'policy_id' => $created['id'], 'content' => 'x']);
        $this->actingAs($user)->postJson($this->url($tenant, "policies/{$created['id']}/publish"), ['policy_version_id' => $version->id]);

        $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);
        $this->actingAs($user)->postJson($this->url($tenant, "policies/{$created['id']}/assign"), ['employee_ids' => [$employee->id]]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'policy.assigned', 'auditable_type' => PolicyAcknowledgement::class]);
    }

    public function test_all_policy_routes_include_tenant_matches_middleware(): void
    {
        $policyRoutes = collect(Route::getRoutes())
            ->filter(fn ($route) => str_starts_with($route->uri(), 'api/v1/policies'));

        $this->assertGreaterThanOrEqual(9, $policyRoutes->count());

        foreach ($policyRoutes as $route) {
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
        $user = $this->userWithPermissions($tenant, 'policies.view');
        $user->update(['status' => User::STATUS_INACTIVE]);

        $response = $this->actingAs($user)->getJson($this->url($tenant, 'policies'));

        $response->assertForbidden();
    }

    public function test_user_under_inactive_tenant_fails_closed(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'policies.view');
        $tenant->update(['status' => Tenant::STATUS_SUSPENDED]);

        $response = $this->actingAs($user)->getJson($this->url($tenant, 'policies'));

        $response->assertForbidden();
    }
}
