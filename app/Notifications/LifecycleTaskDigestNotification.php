<?php

namespace App\Notifications;

use App\Models\LifecycleTask;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Collection;

/**
 * Checkpoint 45 — the app's first custom Notification class. Sent once
 * per assignee per digest run (see SendLifecycleTaskDigest), never once
 * per task — a single email listing every overdue/due-soon task
 * assigned to that person.
 *
 * Deliberately NOT ShouldQueue. QUEUE_CONNECTION=database has been
 * configured since the first checkpoint but nothing has ever actually
 * been queued (see docs/deployment.md §6) — queuing this would mean
 * introducing a *second* new piece of always-on infrastructure in this
 * same checkpoint (a persistent queue:work/supervisor process) on top
 * of the first (the scheduler + its cron entry, see
 * SendLifecycleTaskDigest/bootstrap/app.php). A once-daily digest sent
 * synchronously from within the already-scheduled command is a real
 * email either way; making it queued too is a deliberately deferred
 * follow-up, revisited once a queue worker is genuinely running in
 * production (the same "revisit once actually needed" posture
 * docs/deployment.md already applies to the scheduler itself, prior to
 * this checkpoint).
 *
 * tenantUrl is built by the caller the same tenant-aware way
 * AppServiceProvider::boot()'s ResetPassword::createUrlUsing() already
 * builds the password-reset link ({subdomain}.{base_domain}, scheme
 * from config('app.url')) — this command has no incoming request to
 * infer a host from, since it runs from `php artisan`, not HTTP.
 */
class LifecycleTaskDigestNotification extends Notification
{
    /**
     * @param  Collection<int, LifecycleTask>  $overdueTasks
     * @param  Collection<int, LifecycleTask>  $dueSoonTasks
     */
    public function __construct(
        private readonly Collection $overdueTasks,
        private readonly Collection $dueSoonTasks,
        private readonly string $tenantLifecycleUrl,
    ) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $totalCount = $this->overdueTasks->count() + $this->dueSoonTasks->count();

        $message = (new MailMessage)
            ->subject("You have {$totalCount} onboarding/offboarding task(s) needing attention")
            ->greeting("Hello {$notifiable->name},");

        if ($this->overdueTasks->isNotEmpty()) {
            $message->line('**Overdue:**');

            foreach ($this->overdueTasks as $task) {
                $message->line("- {$task->title} (was due {$task->due_date?->toDateString()})");
            }
        }

        if ($this->dueSoonTasks->isNotEmpty()) {
            $message->line('**Due soon:**');

            foreach ($this->dueSoonTasks as $task) {
                $message->line("- {$task->title} (due {$task->due_date?->toDateString()})");
            }
        }

        return $message
            ->action('View my lifecycle tasks', $this->tenantLifecycleUrl)
            ->line('This is an automated daily reminder. Completed or skipped tasks are never included.');
    }
}
