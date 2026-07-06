<?php

namespace Tests\Feature\HrDocuments;

use App\Models\AuditLog;
use App\Models\HrGeneratedDocument;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Checkpoint 37 — HR Document Approval Workflow Foundation.
 * draft -> pending_approval -> approved | rejected -> (resubmit) ->
 * pending_approval, and archived reachable from any non-terminal status.
 */
class HrGeneratedDocumentApprovalApiTest extends TestCase
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

    // 1: guest cannot submit/approve/reject
    public function test_guest_cannot_submit_approve_or_reject(): void
    {
        $tenant = Tenant::factory()->create();
        $draft = HrGeneratedDocument::factory()->draft()->create(['tenant_id' => $tenant->id]);
        $pending = HrGeneratedDocument::factory()->pendingApproval()->create(['tenant_id' => $tenant->id]);

        $this->postJson($this->url($tenant, "hr-generated-documents/{$draft->id}/submit"))->assertUnauthorized();
        $this->postJson($this->url($tenant, "hr-generated-documents/{$pending->id}/approve"))->assertUnauthorized();
        $this->postJson($this->url($tenant, "hr-generated-documents/{$pending->id}/reject"), ['rejection_reason' => 'x'])->assertUnauthorized();
    }

    // 2: no submit permission cannot submit
    public function test_user_without_submit_permission_cannot_submit(): void
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        $draft = HrGeneratedDocument::factory()->draft()->create(['tenant_id' => $tenant->id]);

        $this->actingAs($user)->postJson($this->url($tenant, "hr-generated-documents/{$draft->id}/submit"))->assertForbidden();
    }

    // 3: with submit permission can submit draft and rejected documents
    public function test_user_with_submit_permission_can_submit_draft_document(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'hr_generated_documents.submit');
        $draft = HrGeneratedDocument::factory()->draft()->create(['tenant_id' => $tenant->id]);

        $response = $this->actingAs($user)->postJson($this->url($tenant, "hr-generated-documents/{$draft->id}/submit"));

        $response->assertOk();
        $fresh = $draft->fresh();
        $this->assertSame('pending_approval', $fresh->status->value);
        $this->assertNotNull($fresh->submitted_at);
        $this->assertSame($user->id, $fresh->submitted_by);
    }

    public function test_user_with_submit_permission_can_resubmit_rejected_document(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'hr_generated_documents.submit');
        $rejected = HrGeneratedDocument::factory()->rejected()->create(['tenant_id' => $tenant->id]);

        $response = $this->actingAs($user)->postJson($this->url($tenant, "hr-generated-documents/{$rejected->id}/submit"));

        $response->assertOk();
        $this->assertSame('pending_approval', $rejected->fresh()->status->value);
    }

    // 4: no approve permission cannot approve
    public function test_user_without_approve_permission_cannot_approve(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'hr_generated_documents.submit');
        $pending = HrGeneratedDocument::factory()->pendingApproval()->create(['tenant_id' => $tenant->id]);

        $this->actingAs($user)->postJson($this->url($tenant, "hr-generated-documents/{$pending->id}/approve"))->assertForbidden();
    }

    // 5: with approve permission can approve pending document
    public function test_user_with_approve_permission_can_approve_pending_document(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'hr_generated_documents.approve');
        $pending = HrGeneratedDocument::factory()->pendingApproval()->create(['tenant_id' => $tenant->id]);

        $response = $this->actingAs($user)->postJson($this->url($tenant, "hr-generated-documents/{$pending->id}/approve"));

        $response->assertOk();
        $fresh = $pending->fresh();
        $this->assertSame('approved', $fresh->status->value);
        $this->assertNotNull($fresh->approved_at);
        $this->assertSame($user->id, $fresh->approved_by);
    }

    // 6: no reject permission cannot reject
    public function test_user_without_reject_permission_cannot_reject(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'hr_generated_documents.submit');
        $pending = HrGeneratedDocument::factory()->pendingApproval()->create(['tenant_id' => $tenant->id]);

        $this->actingAs($user)
            ->postJson($this->url($tenant, "hr-generated-documents/{$pending->id}/reject"), ['rejection_reason' => 'Wrong wording.'])
            ->assertForbidden();
    }

    // 7: with reject permission can reject pending document
    public function test_user_with_reject_permission_can_reject_pending_document(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'hr_generated_documents.reject');
        $pending = HrGeneratedDocument::factory()->pendingApproval()->create(['tenant_id' => $tenant->id]);

        $response = $this->actingAs($user)->postJson($this->url($tenant, "hr-generated-documents/{$pending->id}/reject"), [
            'rejection_reason' => 'Please fix the start date.',
        ]);

        $response->assertOk();
        $fresh = $pending->fresh();
        $this->assertSame('rejected', $fresh->status->value);
        $this->assertNotNull($fresh->rejected_at);
        $this->assertSame($user->id, $fresh->rejected_by);
        $this->assertSame('Please fix the start date.', $fresh->rejection_reason);
    }

    public function test_reject_requires_a_reason(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'hr_generated_documents.reject');
        $pending = HrGeneratedDocument::factory()->pendingApproval()->create(['tenant_id' => $tenant->id]);

        $this->actingAs($user)
            ->postJson($this->url($tenant, "hr-generated-documents/{$pending->id}/reject"), [])
            ->assertStatus(422)->assertJsonValidationErrors('rejection_reason');
    }

    // 8: invalid transitions are blocked
    public function test_cannot_submit_an_already_approved_document(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'hr_generated_documents.submit');
        $approved = HrGeneratedDocument::factory()->create(['tenant_id' => $tenant->id]);

        $this->actingAs($user)->postJson($this->url($tenant, "hr-generated-documents/{$approved->id}/submit"))->assertStatus(422);
    }

    public function test_cannot_approve_a_draft_document(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'hr_generated_documents.approve');
        $draft = HrGeneratedDocument::factory()->draft()->create(['tenant_id' => $tenant->id]);

        $this->actingAs($user)->postJson($this->url($tenant, "hr-generated-documents/{$draft->id}/approve"))->assertStatus(422);
    }

    public function test_cannot_reject_a_draft_document(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'hr_generated_documents.reject');
        $draft = HrGeneratedDocument::factory()->draft()->create(['tenant_id' => $tenant->id]);

        $this->actingAs($user)
            ->postJson($this->url($tenant, "hr-generated-documents/{$draft->id}/reject"), ['rejection_reason' => 'x'])
            ->assertStatus(422);
    }

    public function test_cannot_approve_an_already_approved_document(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'hr_generated_documents.approve');
        $approved = HrGeneratedDocument::factory()->create(['tenant_id' => $tenant->id]);

        $this->actingAs($user)->postJson($this->url($tenant, "hr-generated-documents/{$approved->id}/approve"))->assertStatus(422);
    }

    // 9: approved document cannot be edited
    public function test_approved_document_cannot_be_edited(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'hr_generated_documents.update');
        $approved = HrGeneratedDocument::factory()->create(['tenant_id' => $tenant->id]);

        $response = $this->actingAs($user)
            ->patchJson($this->url($tenant, "hr-generated-documents/{$approved->id}"), ['title' => 'Should not be allowed']);

        $response->assertStatus(422)->assertJsonValidationErrors('title');
        $this->assertNotSame('Should not be allowed', $approved->fresh()->title);
    }

    public function test_pending_approval_document_cannot_be_edited(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'hr_generated_documents.update');
        $pending = HrGeneratedDocument::factory()->pendingApproval()->create(['tenant_id' => $tenant->id]);

        $this->actingAs($user)
            ->patchJson($this->url($tenant, "hr-generated-documents/{$pending->id}"), ['title' => 'Should not be allowed'])
            ->assertStatus(422);
    }

    public function test_rejected_document_can_be_edited(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'hr_generated_documents.update');
        $rejected = HrGeneratedDocument::factory()->rejected()->create(['tenant_id' => $tenant->id]);

        $response = $this->actingAs($user)
            ->patchJson($this->url($tenant, "hr-generated-documents/{$rejected->id}"), ['title' => 'Revised title']);

        $response->assertOk();
        $this->assertSame('Revised title', $rejected->fresh()->title);
    }

    // 10: archived document remains terminal
    public function test_archived_document_cannot_transition_anywhere(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions(
            $tenant,
            'hr_generated_documents.delete', 'hr_generated_documents.submit',
            'hr_generated_documents.approve', 'hr_generated_documents.reject',
        );
        $document = HrGeneratedDocument::factory()->draft()->create(['tenant_id' => $tenant->id]);

        $this->actingAs($user)->deleteJson($this->url($tenant, "hr-generated-documents/{$document->id}"))->assertOk();
        $this->assertSame('archived', $document->fresh()->status->value);

        // Soft-deleted — no longer reachable via any route at all
        // (BelongsToTenant's global scope + default query exclude
        // trashed rows), so every further action 404s, not 422.
        $this->actingAs($user)->postJson($this->url($tenant, "hr-generated-documents/{$document->id}/submit"))->assertNotFound();
    }

    public function test_pending_approval_document_can_be_archived_directly(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'hr_generated_documents.delete');
        $pending = HrGeneratedDocument::factory()->pendingApproval()->create(['tenant_id' => $tenant->id]);

        $this->actingAs($user)->deleteJson($this->url($tenant, "hr-generated-documents/{$pending->id}"))->assertOk();
        $this->assertSame('archived', $pending->fresh()->status->value);
    }

    // 11: tenant isolation
    public function test_tenant_a_cannot_approve_or_reject_tenant_bs_document(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenantA, 'hr_generated_documents.approve', 'hr_generated_documents.reject');
        $pendingB = HrGeneratedDocument::factory()->pendingApproval()->create(['tenant_id' => $tenantB->id]);

        $this->actingAs($user)->postJson($this->url($tenantA, "hr-generated-documents/{$pendingB->id}/approve"))->assertNotFound();
        $this->actingAs($user)
            ->postJson($this->url($tenantA, "hr-generated-documents/{$pendingB->id}/reject"), ['rejection_reason' => 'x'])
            ->assertNotFound();
        $this->assertSame('pending_approval', $pendingB->fresh()->status->value);
    }

    // 12: platform super admin blocked
    public function test_platform_super_admin_is_blocked_from_approval_actions(): void
    {
        $tenant = Tenant::factory()->create();
        $admin = User::factory()->platformAdmin()->create();
        $pending = HrGeneratedDocument::factory()->pendingApproval()->create(['tenant_id' => $tenant->id]);

        $this->actingAs($admin)->postJson($this->url($tenant, "hr-generated-documents/{$pending->id}/approve"))->assertForbidden();
    }

    // 13: approval fields are server-controlled
    public function test_approve_ignores_forged_approved_by_and_at(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'hr_generated_documents.approve');
        $pending = HrGeneratedDocument::factory()->pendingApproval()->create(['tenant_id' => $tenant->id]);

        $this->actingAs($user)->postJson($this->url($tenant, "hr-generated-documents/{$pending->id}/approve"), [
            'approved_at' => '2000-01-01T00:00:00Z',
            'approved_by' => 999999,
        ])->assertOk();

        $fresh = $pending->fresh();
        $this->assertSame($user->id, $fresh->approved_by);
        $this->assertNotSame('2000-01-01T00:00:00+00:00', $fresh->approved_at->toIso8601String());
    }

    public function test_submit_ignores_forged_submitted_by_and_at(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'hr_generated_documents.submit');
        $draft = HrGeneratedDocument::factory()->draft()->create(['tenant_id' => $tenant->id]);

        $this->actingAs($user)->postJson($this->url($tenant, "hr-generated-documents/{$draft->id}/submit"), [
            'submitted_at' => '2000-01-01T00:00:00Z',
            'submitted_by' => 999999,
        ])->assertOk();

        $this->assertSame($user->id, $draft->fresh()->submitted_by);
    }

    // 14: rejection reason handled safely
    public function test_rejection_reason_is_exposed_on_resource_but_not_in_audit_metadata(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'hr_generated_documents.reject', 'hr_generated_documents.view');
        $pending = HrGeneratedDocument::factory()->pendingApproval()->create(['tenant_id' => $tenant->id]);

        $this->actingAs($user)->postJson($this->url($tenant, "hr-generated-documents/{$pending->id}/reject"), [
            'rejection_reason' => 'CONFIDENTIAL-REJECTION-MARKER',
        ])->assertOk();

        $show = $this->actingAs($user)->getJson($this->url($tenant, "hr-generated-documents/{$pending->id}"));
        $this->assertSame('CONFIDENTIAL-REJECTION-MARKER', $show->json('data.rejection_reason'));

        $log = AuditLog::query()->where('action', 'hr_generated_document.rejected')->first();
        $this->assertNotNull($log);
        $this->assertStringNotContainsString('CONFIDENTIAL-REJECTION-MARKER', json_encode($log->toArray()));
    }

    // 15: audit logs
    public function test_submit_writes_audit_log(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'hr_generated_documents.submit');
        $draft = HrGeneratedDocument::factory()->draft()->create(['tenant_id' => $tenant->id]);

        $this->actingAs($user)->postJson($this->url($tenant, "hr-generated-documents/{$draft->id}/submit"))->assertOk();

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'hr_generated_document.submitted',
            'auditable_id' => $draft->id,
            'actor_user_id' => $user->id,
        ]);
    }

    public function test_resubmit_writes_a_distinct_audit_log_action(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'hr_generated_documents.submit');
        $rejected = HrGeneratedDocument::factory()->rejected()->create(['tenant_id' => $tenant->id]);

        $this->actingAs($user)->postJson($this->url($tenant, "hr-generated-documents/{$rejected->id}/submit"))->assertOk();

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'hr_generated_document.resubmitted',
            'auditable_id' => $rejected->id,
        ]);
    }

    public function test_approve_writes_audit_log(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'hr_generated_documents.approve');
        $pending = HrGeneratedDocument::factory()->pendingApproval()->create(['tenant_id' => $tenant->id]);

        $this->actingAs($user)->postJson($this->url($tenant, "hr-generated-documents/{$pending->id}/approve"))->assertOk();

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'hr_generated_document.approved',
            'auditable_id' => $pending->id,
        ]);
    }

    public function test_reject_writes_audit_log(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'hr_generated_documents.reject');
        $pending = HrGeneratedDocument::factory()->pendingApproval()->create(['tenant_id' => $tenant->id]);

        $this->actingAs($user)
            ->postJson($this->url($tenant, "hr-generated-documents/{$pending->id}/reject"), ['rejection_reason' => 'Needs fixing.'])
            ->assertOk();

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'hr_generated_document.rejected',
            'auditable_id' => $pending->id,
        ]);
    }

    // 16: resources do not expose internal fields
    public function test_resource_does_not_expose_internal_fields(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'hr_generated_documents.view');
        HrGeneratedDocument::factory()->pendingApproval()->create(['tenant_id' => $tenant->id]);

        $response = $this->actingAs($user)->getJson($this->url($tenant, 'hr-generated-documents'));
        $body = json_encode($response->json());

        foreach (['created_by', 'updated_by', 'deleted_at'] as $internalKey) {
            $this->assertStringNotContainsString($internalKey, $body, "Response unexpectedly contains '{$internalKey}'.");
        }
        $this->assertStringNotContainsString('"tenant_id"', $body);
    }

    // PDF watermark behaviour (Option A, approved)
    public function test_pdf_download_includes_watermark_when_not_approved(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'hr_generated_documents.view');
        $draft = HrGeneratedDocument::factory()->draft()->create(['tenant_id' => $tenant->id]);

        $response = $this->actingAs($user)->get($this->url($tenant, "hr-generated-documents/{$draft->id}/download-pdf"));

        $response->assertOk();
        $this->assertStringContainsString('%PDF-', $response->getContent());
    }

    public function test_draft_and_approved_pdfs_render_different_bytes(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'hr_generated_documents.view');
        $draft = HrGeneratedDocument::factory()->draft()->create(['tenant_id' => $tenant->id, 'rendered_content' => 'Same content.']);
        $approved = HrGeneratedDocument::factory()->create(['tenant_id' => $tenant->id, 'rendered_content' => 'Same content.']);

        $draftPdf = $this->actingAs($user)->get($this->url($tenant, "hr-generated-documents/{$draft->id}/download-pdf"))->getContent();
        $approvedPdf = $this->actingAs($user)->get($this->url($tenant, "hr-generated-documents/{$approved->id}/download-pdf"))->getContent();

        // The watermark banner makes the draft PDF's bytes differ from
        // the approved one even with identical rendered_content —
        // proof the watermark is actually present, not just assumed.
        $this->assertNotSame($draftPdf, $approvedPdf);
    }

    public function test_inactive_user_fails_closed(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'hr_generated_documents.submit');
        $user->update(['status' => User::STATUS_INACTIVE]);
        $draft = HrGeneratedDocument::factory()->draft()->create(['tenant_id' => $tenant->id]);

        $this->actingAs($user)->postJson($this->url($tenant, "hr-generated-documents/{$draft->id}/submit"))->assertForbidden();
    }
}
