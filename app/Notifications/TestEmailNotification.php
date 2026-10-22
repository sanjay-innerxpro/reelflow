<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;

class TestEmailNotification extends ReelFlowNotification
{
    public function type(): string
    {
        return 'test';
    }

    public function subject(): string
    {
        return '[ReelFlow] Test email';
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject($this->subject())
            ->line('Email delivery from ReelFlow is working.')
            ->line('Sent at '.now()->setTimezone(config('reelflow.display_timezone'))->format('d M Y H:i T').'.');
    }
}
