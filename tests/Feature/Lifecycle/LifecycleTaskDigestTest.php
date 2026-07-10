<?php

namespace Tests\Feature\Lifecycle;

use App\Console\Commands\SendLifecycleTaskDigest;
use App\Models\LifecycleProcess;
use App\Models\LifecycleTask;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\LifecycleTaskDigestNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Checkpoint 45 — the app's first scheduled task
 * (lifecycle:send-task-digest). Notification::fake() is the only way
 * to observe whether a digest was actually sent, same posture
 * PasswordResetTest already established for Checkpoint 44's emails.
 */
class LifecycleTaskDigestTest extends TestCase
{
    use RefreshDatabase;

    public function test_digest_sends_one_email_per_assignee_combining_overdue_and_due_soon_tasks(): void
    {
        Notification::fake();
        $tenant = Tenant::factory()->create();
        $assignee = User::factory()->create(['tenant_id' => $tenant->id]);
        $process = LifecycleProcess::factory()->create(['tenant_id' => $tenant->id]);

        $overdueTask = LifecycleTask::factory()->create([
            'tenant_id' => $tenant->id, 'process_id' => $process->id,
            'assigned_to_user_id' => $assignee->id, 'due_date' => now()->subDays(2),
        ]);
        $dueSoonTask = LifecycleTask::factory()->create([
            'tenant_id' => $tenant->id, 'process_id' => $process->id,
            'assigned_to_user_id' => $assignee->id, 'due_date' => now()->addDay(),
        ]);

        $this->artisan(SendLifecycleTaskDigest::class)->assertSuccessful();

        Notification::assertSentToTimes($assignee, LifecycleTaskDigestNotification::class, 1);
        Notification::assertSentTo(
            $assignee,
            LifecycleTaskDigestNotification::class,
            function (LifecycleTaskDigestNotification $notification) use ($assignee, $overdueTask, $dueSoonTask) {
                $mail = $notification->toMail($assignee);
                $body = implode(' ', array_merge($mail->introLines, $mail->outroLines));

                return str_contains($body, $overdueTask->title) && str_contains($body, $dueSoonTask->title);
            },
        );
    }

    public function test_digest_skips_tasks_without_an_assignee(): void
    {
        Notification::fake();
        $tenant = Tenant::factory()->create();
        $process = LifecycleProcess::factory()->create(['tenant_id' => $tenant->id]);
        LifecycleTask::factory()->create([
            'tenant_id' => $tenant->id, 'process_id' => $process->id,
            'assigned_to_user_id' => null, 'due_date' => now()->subDay(),
        ]);

        $this->artisan(SendLifecycleTaskDigest::class)->assertSuccessful();

        Notification::assertNothingSent();
    }

    public function test_digest_skips_completed_and_skipped_tasks(): void
    {
        Notification::fake();
        $tenant = Tenant::factory()->create();
        $assignee = User::factory()->create(['tenant_id' => $tenant->id]);
        $process = LifecycleProcess::factory()->create(['tenant_id' => $tenant->id]);
        LifecycleTask::factory()->completed()->create([
            'tenant_id' => $tenant->id, 'process_id' => $process->id,
            'assigned_to_user_id' => $assignee->id, 'due_date' => now()->subDay(),
        ]);
        LifecycleTask::factory()->skipped()->create([
            'tenant_id' => $tenant->id, 'process_id' => $process->id,
            'assigned_to_user_id' => $assignee->id, 'due_date' => now()->subDay(),
        ]);

        $this->artisan(SendLifecycleTaskDigest::class)->assertSuccessful();

        Notification::assertNothingSent();
    }

    public function test_digest_skips_tasks_due_beyond_the_due_soon_window(): void
    {
        Notification::fake();
        $tenant = Tenant::factory()->create();
        $assignee = User::factory()->create(['tenant_id' => $tenant->id]);
        $process = LifecycleProcess::factory()->create(['tenant_id' => $tenant->id]);
        LifecycleTask::factory()->create([
            'tenant_id' => $tenant->id, 'process_id' => $process->id,
            'assigned_to_user_id' => $assignee->id, 'due_date' => now()->addDays(30),
        ]);

        $this->artisan(SendLifecycleTaskDigest::class)->assertSuccessful();

        Notification::assertNothingSent();
    }

    public function test_digest_skips_an_inactive_assignee(): void
    {
        Notification::fake();
        $tenant = Tenant::factory()->create();
        $assignee = User::factory()->create(['tenant_id' => $tenant->id, 'status' => User::STATUS_INACTIVE]);
        $process = LifecycleProcess::factory()->create(['tenant_id' => $tenant->id]);
        LifecycleTask::factory()->create([
            'tenant_id' => $tenant->id, 'process_id' => $process->id,
            'assigned_to_user_id' => $assignee->id, 'due_date' => now()->subDay(),
        ]);

        $this->artisan(SendLifecycleTaskDigest::class)->assertSuccessful();

        Notification::assertNothingSent();
    }

    public function test_digest_skips_a_suspended_tenant(): void
    {
        Notification::fake();
        $tenant = Tenant::factory()->create(['status' => Tenant::STATUS_SUSPENDED]);
        $assignee = User::factory()->create(['tenant_id' => $tenant->id]);
        $process = LifecycleProcess::factory()->create(['tenant_id' => $tenant->id]);
        LifecycleTask::factory()->create([
            'tenant_id' => $tenant->id, 'process_id' => $process->id,
            'assigned_to_user_id' => $assignee->id, 'due_date' => now()->subDay(),
        ]);

        $this->artisan(SendLifecycleTaskDigest::class)->assertSuccessful();

        Notification::assertNothingSent();
    }

    public function test_digest_is_tenant_isolated(): void
    {
        Notification::fake();
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();
        $assigneeA = User::factory()->create(['tenant_id' => $tenantA->id]);
        $assigneeB = User::factory()->create(['tenant_id' => $tenantB->id]);
        $processA = LifecycleProcess::factory()->create(['tenant_id' => $tenantA->id]);
        $processB = LifecycleProcess::factory()->create(['tenant_id' => $tenantB->id]);
        $taskA = LifecycleTask::factory()->create([
            'tenant_id' => $tenantA->id, 'process_id' => $processA->id,
            'assigned_to_user_id' => $assigneeA->id, 'due_date' => now()->subDay(), 'title' => 'Tenant A task',
        ]);
        $taskB = LifecycleTask::factory()->create([
            'tenant_id' => $tenantB->id, 'process_id' => $processB->id,
            'assigned_to_user_id' => $assigneeB->id, 'due_date' => now()->subDay(), 'title' => 'Tenant B task',
        ]);

        $this->artisan(SendLifecycleTaskDigest::class)->assertSuccessful();

        Notification::assertSentToTimes($assigneeA, LifecycleTaskDigestNotification::class, 1);
        Notification::assertSentToTimes($assigneeB, LifecycleTaskDigestNotification::class, 1);

        Notification::assertSentTo($assigneeA, LifecycleTaskDigestNotification::class, function (LifecycleTaskDigestNotification $notification) use ($assigneeA, $taskA, $taskB) {
            $body = implode(' ', array_merge($notification->toMail($assigneeA)->introLines, $notification->toMail($assigneeA)->outroLines));

            return str_contains($body, $taskA->title) && ! str_contains($body, $taskB->title);
        });
    }

    public function test_digest_writes_one_audit_log_per_tenant_with_recipients(): void
    {
        Notification::fake();
        $tenant = Tenant::factory()->create();
        $assignee = User::factory()->create(['tenant_id' => $tenant->id]);
        $process = LifecycleProcess::factory()->create(['tenant_id' => $tenant->id]);
        LifecycleTask::factory()->create([
            'tenant_id' => $tenant->id, 'process_id' => $process->id,
            'assigned_to_user_id' => $assignee->id, 'due_date' => now()->subDay(),
        ]);

        $this->artisan(SendLifecycleTaskDigest::class)->assertSuccessful();

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'lifecycle_task_digest.sent',
            'module' => 'lifecycle',
            'tenant_id' => $tenant->id,
            'actor_type' => 'system',
        ]);
    }

    public function test_digest_command_succeeds_and_sends_nothing_when_there_is_nothing_due(): void
    {
        Notification::fake();
        $tenant = Tenant::factory()->create();

        $this->artisan(SendLifecycleTaskDigest::class)->assertSuccessful();

        Notification::assertNothingSent();
        $this->assertDatabaseCount('audit_logs', 0);
    }
}
