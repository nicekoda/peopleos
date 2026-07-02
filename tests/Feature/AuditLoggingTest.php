<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use RuntimeException;
use Tests\TestCase;

class AuditLoggingTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_success_creates_audit_log(): void
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->create([
            'tenant_id' => $tenant->id,
            'password' => Hash::make('secret-password'),
        ]);

        $this->post('http://'.$tenant->subdomain.'.'.config('tenancy.base_domain').'/login', [
            'email' => $user->email,
            'password' => 'secret-password',
        ])->assertOk();

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'login.success',
            'module' => 'auth',
            'actor_user_id' => $user->id,
            'target_user_id' => $user->id,
        ]);
    }

    public function test_logout_creates_audit_log(): void
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->create(['tenant_id' => $tenant->id]);

        $this->actingAs($user)
            ->post('http://'.$tenant->subdomain.'.'.config('tenancy.base_domain').'/logout')
            ->assertOk();

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'logout',
            'module' => 'auth',
            'actor_user_id' => $user->id,
        ]);
    }

    public function test_role_assignment_creates_audit_log(): void
    {
        $tenant = Tenant::factory()->create();
        $performer = User::factory()->create(['tenant_id' => $tenant->id]);
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        $role = Role::factory()->create(['tenant_id' => $tenant->id]);

        $user->assignRole($role, $performer);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'role.assigned',
            'module' => 'rbac',
            'actor_user_id' => $performer->id,
            'target_user_id' => $user->id,
            'auditable_type' => Role::class,
            'auditable_id' => (string) $role->id,
        ]);
    }

    public function test_role_removal_creates_audit_log(): void
    {
        $tenant = Tenant::factory()->create();
        $performer = User::factory()->create(['tenant_id' => $tenant->id]);
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        $role = Role::factory()->create(['tenant_id' => $tenant->id]);
        $user->assignRole($role);

        $user->removeRole($role, $performer);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'role.removed',
            'module' => 'rbac',
            'actor_user_id' => $performer->id,
            'target_user_id' => $user->id,
        ]);
    }

    public function test_direct_permission_grant_creates_audit_log(): void
    {
        $tenant = Tenant::factory()->create();
        $performer = User::factory()->create(['tenant_id' => $tenant->id]);
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        $permission = Permission::factory()->create(['key' => 'documents.download']);

        $user->grantPermission($permission, $performer, 'Testing.');

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'permission.granted',
            'module' => 'rbac',
            'actor_user_id' => $performer->id,
            'target_user_id' => $user->id,
            'auditable_type' => Permission::class,
            'auditable_id' => (string) $permission->id,
        ]);
    }

    public function test_direct_permission_revocation_creates_audit_log(): void
    {
        $tenant = Tenant::factory()->create();
        $performer = User::factory()->create(['tenant_id' => $tenant->id]);
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        $permission = Permission::factory()->create(['key' => 'documents.download']);
        $user->grantPermission($permission);

        $user->revokePermission($permission, $performer);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'permission.revoked',
            'module' => 'rbac',
            'actor_user_id' => $performer->id,
            'target_user_id' => $user->id,
        ]);
    }

    public function test_audit_log_records_correct_tenant_id_for_tenant_user_action(): void
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        $role = Role::factory()->create(['tenant_id' => $tenant->id]);

        $user->assignRole($role);

        $log = AuditLog::query()->where('action', 'role.assigned')->latest('id')->first();

        $this->assertNotNull($log);
        $this->assertSame($tenant->id, $log->tenant_id);
    }

    public function test_platform_level_action_can_create_audit_log_with_nullable_tenant_id(): void
    {
        $admin = User::factory()->platformAdmin()->create();
        $role = Role::factory()->platform()->create();

        $admin->assignRole($role);

        $log = AuditLog::query()->where('action', 'role.assigned')->where('target_user_id', $admin->id)->latest('id')->first();

        $this->assertNotNull($log);
        $this->assertNull($log->tenant_id);
    }

    public function test_audit_log_does_not_store_sensitive_values(): void
    {
        AuditLogger::log(
            action: 'test.sensitive',
            module: 'test',
            oldValues: ['password' => 'super-secret', 'name' => 'Ada'],
            newValues: ['bank_account_number' => '1234567890', 'national_id' => 'X123', 'name' => 'Ada Lovelace'],
        );

        $log = AuditLog::query()->where('action', 'test.sensitive')->firstOrFail();

        $this->assertSame('***MASKED***', $log->old_values['password']);
        $this->assertSame('Ada', $log->old_values['name']);
        $this->assertSame('***MASKED***', $log->new_values['bank_account_number']);
        $this->assertSame('***MASKED***', $log->new_values['national_id']);
        $this->assertSame('Ada Lovelace', $log->new_values['name']);
    }

    /**
     * No audit log viewing endpoint exists yet — audit logging is
     * foundation-only at this checkpoint (no Audit UI, per scope). Add a
     * real cross-tenant access test once a viewing endpoint exists.
     */
    public function test_tenant_user_cannot_access_audit_logs_from_another_tenant(): void
    {
        $this->markTestSkipped(
            'No audit log viewing endpoint exists yet. This checkpoint is logging-only '
            .'(writing audit_logs rows); a read/viewing endpoint with tenant-scoped access '
            .'control is future scope. Revisit when that endpoint is built.'
        );
    }

    public function test_audit_log_cannot_be_updated(): void
    {
        $log = AuditLog::factory()->create();

        $this->expectException(RuntimeException::class);

        $log->description = 'edited';
        $log->save();
    }

    public function test_audit_log_cannot_be_deleted(): void
    {
        $log = AuditLog::factory()->create();

        $this->expectException(RuntimeException::class);

        $log->delete();
    }
}
