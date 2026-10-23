<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Sent immediately (not queued) so the administrator sees delivery errors.
 */
class InviteNotification extends Notification
{
    public function __construct(private readonly string $url, private readonly string $invitedBy) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('You are invited to '.config('app.name'))
            ->greeting('Hello '.$notifiable->name.',')
            ->line("{$this->invitedBy} invited you to ".config('app.name').', where you can connect your Facebook Page and Instagram account, upload reels and let them post automatically.')
            ->action('Set your password', $this->url)
            ->line('This link is valid for 7 days.');
    }
}
