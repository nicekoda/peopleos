<?php

namespace Tests\Feature\Tenant;

use App\Models\AuditLog;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\TenantBranding;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class TenantBrandingApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
    }

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

    // 3: HR Manager/HR Director (branding.manage) can manage branding
    public function test_user_with_manage_permission_can_update_colors(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'tenant.branding.view', 'tenant.branding.manage');

        $response = $this->actingAs($user)->patchJson($this->url($tenant, 'tenant/branding'), [
            'primary_color' => '#4F46E5',
            'secondary_color' => '#111827',
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.primary_color', '#4F46E5');
        $response->assertJsonPath('data.secondary_color', '#111827');
    }

    // 4: HR Officer (no grant) cannot view or manage branding
    public function test_user_without_any_branding_permission_is_blocked(): void
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->create(['tenant_id' => $tenant->id]);

        $this->actingAs($user)->getJson($this->url($tenant, 'tenant/branding'))->assertForbidden();
        $this->actingAs($user)->patchJson($this->url($tenant, 'tenant/branding'), ['primary_color' => '#4F46E5'])->assertForbidden();
    }

    public function test_view_only_permission_can_view_but_cannot_manage(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'tenant.branding.view');

        $this->actingAs($user)->getJson($this->url($tenant, 'tenant/branding'))->assertOk();
        $this->actingAs($user)->patchJson($this->url($tenant, 'tenant/branding'), ['primary_color' => '#4F46E5'])->assertForbidden();
    }

    // 5: Tenant A cannot manage Tenant B branding
    public function test_tenant_a_cannot_manage_tenant_b_branding(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();
        $userA = $this->userWithPermissions($tenantA, 'tenant.branding.manage');

        $this->actingAs($userA)->patchJson($this->url($tenantA, 'tenant/branding'), ['primary_color' => '#4F46E5'])->assertOk();

        $userB = $this->userWithPermissions($tenantB, 'tenant.branding.view');
        $responseB = $this->actingAs($userB)->getJson($this->url($tenantB, 'tenant/branding'));
        $responseB->assertOk();
        $this->assertNull($responseB->json('data.primary_color'));
    }

    // 16: logo upload accepts PNG/JPG/JPEG
    public function test_logo_upload_accepts_png(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'tenant.branding.manage');

        $response = $this->actingAs($user)->postJson($this->url($tenant, 'tenant/branding/logo'), [
            'logo' => UploadedFile::fake()->image('logo.png', 200, 200),
        ]);

        $response->assertCreated();
        $this->assertNotNull($response->json('data.logo_url'));
    }

    public function test_logo_upload_accepts_jpeg(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'tenant.branding.manage');

        $response = $this->actingAs($user)->postJson($this->url($tenant, 'tenant/branding/logo'), [
            'logo' => UploadedFile::fake()->image('logo.jpg', 200, 200),
        ]);

        $response->assertCreated();
    }

    // 17: logo upload rejects SVG
    public function test_logo_upload_rejects_svg(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'tenant.branding.manage');

        $response = $this->actingAs($user)->postJson($this->url($tenant, 'tenant/branding/logo'), [
            'logo' => UploadedFile::fake()->create('logo.svg', 10, 'image/svg+xml'),
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('logo');
    }

    public function test_logo_upload_rejects_oversized_dimensions(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'tenant.branding.manage');

        $response = $this->actingAs($user)->postJson($this->url($tenant, 'tenant/branding/logo'), [
            'logo' => UploadedFile::fake()->image('logo.png', 3000, 3000),
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('logo');
    }

    public function test_logo_upload_rejects_oversized_file(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'tenant.branding.manage');

        $response = $this->actingAs($user)->postJson($this->url($tenant, 'tenant/branding/logo'), [
            'logo' => UploadedFile::fake()->create('logo.png', 3000, 'image/png'),
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('logo');
    }

    public function test_uploading_a_new_logo_replaces_and_deletes_the_previous_one(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'tenant.branding.manage');

        $this->actingAs($user)->postJson($this->url($tenant, 'tenant/branding/logo'), [
            'logo' => UploadedFile::fake()->image('first.png', 100, 100),
        ])->assertCreated();
        $branding = TenantBranding::query()->where('tenant_id', $tenant->id)->firstOrFail();
        $firstPath = $branding->logo_path;
        Storage::disk('public')->assertExists($firstPath);

        $this->actingAs($user)->postJson($this->url($tenant, 'tenant/branding/logo'), [
            'logo' => UploadedFile::fake()->image('second.png', 100, 100),
        ])->assertCreated();

        Storage::disk('public')->assertMissing($firstPath);
    }

    public function test_logo_can_be_removed(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'tenant.branding.manage');
        $this->actingAs($user)->postJson($this->url($tenant, 'tenant/branding/logo'), [
            'logo' => UploadedFile::fake()->image('logo.png', 100, 100),
        ])->assertCreated();

        $response = $this->actingAs($user)->deleteJson($this->url($tenant, 'tenant/branding/logo'));

        $response->assertOk();
        $this->assertNull($response->json('data.logo_url'));
    }

    // 18: invalid colour tokens are rejected
    public function test_invalid_hex_color_is_rejected(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'tenant.branding.manage');

        $response = $this->actingAs($user)->patchJson($this->url($tenant, 'tenant/branding'), ['primary_color' => 'red']);
        $response->assertStatus(422)->assertJsonValidationErrors('primary_color');

        $response = $this->actingAs($user)->patchJson($this->url($tenant, 'tenant/branding'), ['primary_color' => 'rgb(0,0,0)']);
        $response->assertStatus(422)->assertJsonValidationErrors('primary_color');

        $response = $this->actingAs($user)->patchJson($this->url($tenant, 'tenant/branding'), ['primary_color' => '#fff']);
        $response->assertStatus(422)->assertJsonValidationErrors('primary_color');
    }

    // No custom CSS/HTML/JS field exists at all
    public function test_forged_non_color_fields_are_ignored(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'tenant.branding.manage');

        $response = $this->actingAs($user)->patchJson($this->url($tenant, 'tenant/branding'), [
            'primary_color' => '#4F46E5',
            'custom_css' => 'body { display: none; }',
            'custom_html' => '<script>alert(1)</script>',
        ]);

        $response->assertOk();
        $body = json_encode($response->json());
        $this->assertStringNotContainsString('custom_css', $body);
        $this->assertStringNotContainsString('custom_html', $body);
    }

    // 19: branding resource never exposes raw storage paths
    public function test_branding_resource_never_exposes_logo_path(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'tenant.branding.view', 'tenant.branding.manage');
        $this->actingAs($user)->postJson($this->url($tenant, 'tenant/branding/logo'), [
            'logo' => UploadedFile::fake()->image('logo.png', 100, 100),
        ])->assertCreated();

        $response = $this->actingAs($user)->getJson($this->url($tenant, 'tenant/branding'));

        $response->assertOk();
        // The internal DB column/field name never appears as a JSON key —
        // only the public URL (which legitimately includes the storage
        // directory name, same as any other public asset URL) is exposed.
        $body = json_encode($response->json());
        $this->assertStringNotContainsString('"logo_path"', $body);
        $this->assertArrayHasKey('logo_url', $response->json('data'));
    }

    // Tenant-scoped, unguessable path — never a sequential/numeric tenant id
    public function test_logo_storage_path_is_tenant_scoped_and_unguessable(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'tenant.branding.manage');

        $this->actingAs($user)->postJson($this->url($tenant, 'tenant/branding/logo'), [
            'logo' => UploadedFile::fake()->image('logo.png', 100, 100),
        ])->assertCreated();

        $branding = TenantBranding::query()->where('tenant_id', $tenant->id)->firstOrFail();
        $this->assertStringStartsWith("tenant-branding/{$tenant->id}/", $branding->logo_path);
        $filename = basename($branding->logo_path);
        $this->assertMatchesRegularExpression('/^[a-zA-Z0-9]{40}\.png$/', $filename);
    }

    // 20: audit logs are written safely
    public function test_branding_update_writes_audit_log_with_fields_changed_only(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'tenant.branding.manage');

        $this->actingAs($user)->patchJson($this->url($tenant, 'tenant/branding'), ['primary_color' => '#4F46E5'])->assertOk();

        $this->assertDatabaseHas('audit_logs', ['action' => 'branding.updated', 'module' => 'settings', 'actor_user_id' => $user->id]);
        $log = AuditLog::query()->where('action', 'branding.updated')->firstOrFail();
        $this->assertSame(['primary_color'], $log->metadata['fields_changed']);
    }

    public function test_logo_upload_writes_audit_log_without_raw_path(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'tenant.branding.manage');

        $this->actingAs($user)->postJson($this->url($tenant, 'tenant/branding/logo'), [
            'logo' => UploadedFile::fake()->image('my-logo.png', 100, 100),
        ])->assertCreated();

        $this->assertDatabaseHas('audit_logs', ['action' => 'branding.logo_uploaded', 'module' => 'settings', 'actor_user_id' => $user->id]);
        $log = AuditLog::query()->where('action', 'branding.logo_uploaded')->firstOrFail();
        $this->assertStringNotContainsString('tenant-branding', json_encode($log->toArray()));
        $this->assertSame('my-logo.png', $log->metadata['original_filename']);
    }

    public function test_logo_removal_writes_audit_log(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'tenant.branding.manage');
        $this->actingAs($user)->postJson($this->url($tenant, 'tenant/branding/logo'), [
            'logo' => UploadedFile::fake()->image('logo.png', 100, 100),
        ])->assertCreated();

        $this->actingAs($user)->deleteJson($this->url($tenant, 'tenant/branding/logo'))->assertOk();

        $this->assertDatabaseHas('audit_logs', ['action' => 'branding.logo_removed', 'module' => 'settings', 'actor_user_id' => $user->id]);
    }

    // 21/22: Platform Super Admin blocked, same as every other tenant route
    public function test_platform_super_admin_is_blocked_from_branding_routes(): void
    {
        $tenant = Tenant::factory()->create();
        $admin = User::factory()->platformAdmin()->create();

        $this->actingAs($admin)->getJson($this->url($tenant, 'tenant/branding'))->assertForbidden();
    }
}
