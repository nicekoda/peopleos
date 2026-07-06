<?php

namespace Tests\Feature\HrDocuments;

use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\HrGeneratedDocument;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Checkpoint 35 — GET /api/v1/hr-generated-documents/{id}/download-pdf.
 * Option B (approved): rendered on demand from rendered_content, never
 * stored — so there is no storage path to leak and nothing on disk to
 * clean up between tests.
 */
class HrGeneratedDocumentPdfDownloadTest extends TestCase
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

    // 1: guest cannot download
    public function test_guest_cannot_download_pdf(): void
    {
        $tenant = Tenant::factory()->create();
        $document = HrGeneratedDocument::factory()->create(['tenant_id' => $tenant->id]);

        $this->getJson($this->url($tenant, "hr-generated-documents/{$document->id}/download-pdf"))
            ->assertUnauthorized();
    }

    // 2: no permission cannot download
    public function test_user_without_permission_cannot_download_pdf(): void
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        $document = HrGeneratedDocument::factory()->create(['tenant_id' => $tenant->id]);

        $this->actingAs($user)
            ->get($this->url($tenant, "hr-generated-documents/{$document->id}/download-pdf"))
            ->assertForbidden();
    }

    // 3: with permission can download, correct content type, real PDF bytes
    public function test_user_with_permission_can_download_pdf(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'hr_generated_documents.view');
        $employee = Employee::factory()->create(['tenant_id' => $tenant->id, 'first_name' => 'Jane', 'last_name' => 'Doe']);
        $document = HrGeneratedDocument::factory()->create([
            'tenant_id' => $tenant->id,
            'employee_id' => $employee->id,
            'title' => 'Employment Letter',
            'rendered_content' => 'Dear Jane Doe, this confirms your employment.',
        ]);

        $response = $this->actingAs($user)
            ->get($this->url($tenant, "hr-generated-documents/{$document->id}/download-pdf"));

        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/pdf');
        $response->assertHeader('Content-Disposition', 'attachment; filename="employment-letter.pdf"');
        $this->assertStringStartsWith('%PDF-', $response->getContent());
    }

    // 4: tenant isolation
    public function test_tenant_a_cannot_download_tenant_bs_document_pdf(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenantA, 'hr_generated_documents.view');
        $documentB = HrGeneratedDocument::factory()->create(['tenant_id' => $tenantB->id]);

        $this->actingAs($user)
            ->get($this->url($tenantA, "hr-generated-documents/{$documentB->id}/download-pdf"))
            ->assertNotFound();
    }

    // 5: platform super admin blocked
    public function test_platform_super_admin_is_blocked_from_pdf_download(): void
    {
        $tenant = Tenant::factory()->create();
        $admin = User::factory()->platformAdmin()->create();
        $document = HrGeneratedDocument::factory()->create(['tenant_id' => $tenant->id]);

        $this->actingAs($admin)
            ->get($this->url($tenant, "hr-generated-documents/{$document->id}/download-pdf"))
            ->assertForbidden();
    }

    // 7: no private storage path exposed anywhere in the response
    public function test_pdf_response_does_not_expose_storage_path(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'hr_generated_documents.view');
        $document = HrGeneratedDocument::factory()->create(['tenant_id' => $tenant->id]);

        $response = $this->actingAs($user)
            ->get($this->url($tenant, "hr-generated-documents/{$document->id}/download-pdf"));

        $response->assertOk();
        $this->assertStringNotContainsString(storage_path(), $response->getContent());
        $this->assertStringNotContainsString('storage/app', $response->getContent());
        foreach ($response->headers->all() as $header) {
            foreach ($header as $value) {
                $this->assertStringNotContainsString(storage_path(), $value);
            }
        }
    }

    // 8: downloading does not mutate the document
    public function test_downloading_pdf_does_not_mutate_document(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'hr_generated_documents.view');
        $document = HrGeneratedDocument::factory()->create(['tenant_id' => $tenant->id]);
        // A fresh re-fetch, not the in-memory model straight from
        // factory()->create() — Eloquent only populates $attributes with
        // what was explicitly set/mass-assigned until the model is
        // reloaded, so comparing against the in-memory instance would
        // report every DB-default column (null here) as a "new" key.
        $before = $document->fresh()->toArray();

        $this->actingAs($user)
            ->get($this->url($tenant, "hr-generated-documents/{$document->id}/download-pdf"))
            ->assertOk();

        $after = $document->fresh()->toArray();
        unset($before['updated_at'], $after['updated_at']);
        // assertEquals, not assertSame — toArray()'s key order isn't
        // guaranteed identical between the in-memory model and a fresh
        // re-fetch; only the values matter for "did this mutate?".
        $this->assertEquals($before, $after);
    }

    // download writes an audit log, but never the rendered letter content
    public function test_download_pdf_writes_audit_log_without_full_content(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'hr_generated_documents.view');
        $document = HrGeneratedDocument::factory()->create([
            'tenant_id' => $tenant->id,
            'rendered_content' => 'CONFIDENTIAL-MARKER-TEXT-IN-LETTER-BODY',
        ]);

        $this->actingAs($user)
            ->get($this->url($tenant, "hr-generated-documents/{$document->id}/download-pdf"))
            ->assertOk();

        $log = AuditLog::query()->where('action', 'hr_generated_document.pdf_downloaded')->first();
        $this->assertNotNull($log);
        $this->assertSame($tenant->id, $log->tenant_id);
        $this->assertSame($user->id, $log->actor_user_id);
        $this->assertStringNotContainsString('CONFIDENTIAL-MARKER-TEXT-IN-LETTER-BODY', json_encode($log->toArray()));
    }

    public function test_download_pdf_route_includes_tenant_matches_middleware(): void
    {
        $routes = collect(Route::getRoutes())
            ->filter(fn ($route) => str_contains($route->uri(), 'hr-generated-documents') && str_contains($route->uri(), 'download-pdf'));

        $this->assertGreaterThanOrEqual(1, $routes->count());

        foreach ($routes as $route) {
            $this->assertContains('tenant.matches', $route->gatherMiddleware());
        }
    }

    public function test_inactive_user_fails_closed(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'hr_generated_documents.view');
        $user->update(['status' => User::STATUS_INACTIVE]);
        $document = HrGeneratedDocument::factory()->create(['tenant_id' => $tenant->id]);

        $this->actingAs($user)
            ->get($this->url($tenant, "hr-generated-documents/{$document->id}/download-pdf"))
            ->assertForbidden();
    }
}
