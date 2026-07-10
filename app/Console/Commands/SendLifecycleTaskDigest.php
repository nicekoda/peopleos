<?php

namespace App\Console\Commands;

use App\Enums\LifecycleTaskStatus;
use App\Models\LifecycleTask;
use App\Models\Tenant;
use App\Notifications\LifecycleTaskDigestNotification;
use App\Services\Audit\AuditLogger;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Checkpoint 45 — the app's first scheduled task (see
 * bootstrap/app.php's ->withSchedule()). For every active tenant, finds
 * every non-terminal (pending/in_progress) lifecycle task that is
 * either overdue or due within DUE_SOON_WITHIN_DAYS, groups them by
 * assignee, and sends each assignee a single digest email — never one
 * email per task. Tasks with no assignee are silently skipped: there is
 * no one to remind (the same "assignment is required for a reminder to
 * mean anything" reasoning DashboardController's self-scoped cards
 * already rely on).
 *
 * Binds each tenant into the container in turn
 * (app()->instance(Tenant::class, $tenant)) exactly the way
 * ResolveTenant middleware does per-request — outside a request there
 * is no subdomain to resolve one from, so this command must do the
 * equivalent itself before any tenant-scoped query (LifecycleTask::
 * query() below) will filter correctly.
 *
 * Unlike AuditTenantRouteScoping (read-only, safe to re-run any time),
 * this command has a real external side effect — sending email — so it
 * is scheduled exactly once daily, not exposed as an on-demand
 * HTTP-reachable action.
 */
#[Signature('lifecycle:send-task-digest')]
#[Description('Email each assignee a digest of their overdue and due-soon onboarding/offboarding tasks, tenant by tenant.')]
class SendLifecycleTaskDigest extends Command
{
    private const DUE_SOON_WITHIN_DAYS = 3;

    public function handle(): int
    {
        $tenants = Tenant::query()->where('status', Tenant::STATUS_ACTIVE)->get();

        $totalSent = 0;

        foreach ($tenants as $tenant) {
            $totalSent += $this->digestForTenant($tenant);
        }

        $this->info("Lifecycle task digests sent: {$totalSent} across {$tenants->count()} active tenant(s).");

        return self::SUCCESS;
    }

    private function digestForTenant(Tenant $tenant): int
    {
        app()->instance(Tenant::class, $tenant);

        $today = now()->toDateString();
        $dueSoonUntil = now()->addDays(self::DUE_SOON_WITHIN_DAYS)->toDateString();

        $tasks = LifecycleTask::query()
            ->whereNotNull('assigned_to_user_id')
            ->whereNotNull('due_date')
            ->whereIn('status', [LifecycleTaskStatus::Pending->value, LifecycleTaskStatus::InProgress->value])
            ->where('due_date', '<=', $dueSoonUntil)
            ->with('assignedToUser')
            ->get();

        if ($tasks->isEmpty()) {
            return 0;
        }

        $tenantLifecycleUrl = $this->tenantLifecycleUrl($tenant);
        $recipientCount = 0;

        foreach ($tasks->groupBy('assigned_to_user_id') as $assigneeTasks) {
            $assignee = $assigneeTasks->first()->assignedToUser;

            // Re-checked here, not just at assignment time — an
            // assignee can be deactivated after a task was assigned to
            // them, and StoreLifecycleTaskRequest/
            // UpdateLifecycleTaskRequest only guarantee "was active when
            // assigned," not "is still active now."
            if ($assignee === null || ! $assignee->isActive()) {
                continue;
            }

            $overdueTasks = $assigneeTasks
                ->filter(fn (LifecycleTask $task) => $task->due_date->toDateString() < $today)
                ->values();

            $dueSoonTasks = $assigneeTasks
                ->filter(fn (LifecycleTask $task) => $task->due_date->toDateString() >= $today)
                ->values();

            $assignee->notify(new LifecycleTaskDigestNotification($overdueTasks, $dueSoonTasks, $tenantLifecycleUrl));

            $recipientCount++;
        }

        if ($recipientCount > 0) {
            AuditLogger::log(
                action: 'lifecycle_task_digest.sent',
                module: 'lifecycle',
                tenantId: $tenant->id,
                description: "Lifecycle task digest sent to {$recipientCount} assignee(s) ({$tasks->count()} task(s)).",
                metadata: ['recipient_count' => $recipientCount, 'task_count' => $tasks->count()],
            );
        }

        return $recipientCount;
    }

    /**
     * Same tenant-aware URL construction as
     * AppServiceProvider::boot()'s ResetPassword::createUrlUsing() —
     * {subdomain}.{base_domain}, scheme from config('app.url').
     */
    private function tenantLifecycleUrl(Tenant $tenant): string
    {
        $baseDomain = config('tenancy.base_domain');
        $scheme = str_starts_with(config('app.url'), 'https') ? 'https' : 'http';

        return "{$scheme}://{$tenant->subdomain}.{$baseDomain}/lifecycle";
    }
}
