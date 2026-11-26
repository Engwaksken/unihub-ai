<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Sent synchronously (not queued) so the single-use token never sits in the
 * jobs table.
 */
class StaffInvitation extends Notification
{
    public function __construct(private readonly string $token) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $hours = (int) config('cms.invitation_ttl_hours', 24);

        return (new MailMessage)
            ->subject('Your '.config('app.name').' staff account')
            ->greeting('Hello '.$notifiable->name.',')
            ->line('You have been invited to the '.config('app.name').' staff portal.')
            ->line('Use the button below to set your password. You will then be asked to set up an authenticator app (two-factor authentication is required for all staff).')
            ->action('Set your password', route('invitations.show', ['token' => $this->token]))
            ->line("This link can be used once and expires in {$hours} hours.")
            ->line('If you were not expecting this invitation, you can ignore this e-mail.');
    }
}
