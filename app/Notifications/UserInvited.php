<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Checkpoint 46 — sent once, right after Checkpoint 43's account
 * creation, when the creator chooses "send invite" instead of setting a
 * password directly (StoreUserRequest's send_invite: true). Points to
 * the exact same /reset-password/{token} page Checkpoint 44 already
 * built — "set a password given a valid token" is identical whether
 * this is a genuine forgot-password request or a brand-new account's
 * first-ever password, so no new route, page, or token table was
 * needed here, only new wording. Deliberately NOT a reuse/subclass of
 * Illuminate\Auth\Notifications\ResetPassword — that class's default
 * "Reset Password" subject/copy would be a confusing thing to send
 * someone who never had a password to reset in the first place.
 *
 * The token itself comes from Password::createToken($user), called
 * directly by UserController::store() rather than
 * Password::sendResetLink() — the latter always sends Laravel's
 * built-in ResetPassword notification internally
 * (CanResetPassword::sendPasswordResetNotification() is hardcoded to
 * it), which would defeat the point of this class existing at all.
 *
 * Builds its tenant-aware URL the same way
 * AppServiceProvider::boot()'s ResetPassword::createUrlUsing() and
 * SendLifecycleTaskDigest::tenantLifecycleUrl() (Checkpoint 45) already
 * do — duplicated a third time now, not extracted into a shared
 * helper, the same "duplicate until a real need for one appears"
 * posture this app has applied repeatedly (e.g. the tenant-eligibility
 * check duplicated across LoginRequest/ForgotPasswordRequest/
 * ResetPasswordRequest). Worth revisiting if a fourth call site ever
 * needs the identical logic.
 */
class UserInvited extends Notification
{
    public function __construct(private readonly string $token) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $baseDomain = config('tenancy.base_domain');
        $host = $notifiable->is_platform_admin || ! $notifiable->tenant
            ? $baseDomain
            : $notifiable->tenant->subdomain.'.'.$baseDomain;
        $scheme = str_starts_with(config('app.url'), 'https') ? 'https' : 'http';
        $url = "{$scheme}://{$host}/reset-password/{$this->token}?".http_build_query(['email' => $notifiable->email]);

        return (new MailMessage)
            ->subject('Welcome to PeopleOS — set your password')
            ->greeting("Hello {$notifiable->name},")
            ->line('An account has been created for you on PeopleOS.')
            ->action('Set your password', $url)
            ->line("This link works the same way a password-reset link does — if you weren't expecting this, you can safely ignore it.");
    }
}
