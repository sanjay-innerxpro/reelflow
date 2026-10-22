<?php

namespace App\Listeners;

use App\Models\NotificationLog;
use App\Notifications\ReelFlowNotification;
use App\Support\SecretRedactor;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Notifications\Events\NotificationFailed;
use Illuminate\Notifications\Events\NotificationSent;

class LogNotificationDelivery
{
    public function handleSent(NotificationSent $event): void
    {
        $this->record($event->notifiable, $event->notification, $event->channel, 'sent');
    }

    public function handleFailed(NotificationFailed $event): void
    {
        $error = $event->data['exception'] ?? null;
        $this->record($event->notifiable, $event->notification, $event->channel, 'failed', $error instanceof \Throwable ? $error->getMessage() : null);
    }

    private function record(object $notifiable, object $notification, string $channel, string $status, ?string $error = null): void
    {
        if (! $notification instanceof ReelFlowNotification) {
            return;
        }

        $recipient = $notifiable instanceof AnonymousNotifiable
            ? implode(', ', (array) $notifiable->routeNotificationFor('mail'))
            : (string) ($notifiable->email ?? '');

        $related = $notification->related();

        NotificationLog::create([
            'type' => $notification->type(),
            'channel' => $channel,
            'recipient' => mb_substr($recipient, 0, 255),
            'subject' => mb_substr($notification->subject(), 0, 255),
            'status' => $status,
            'related_type' => $related?->getMorphClass(),
            'related_id' => $related?->getKey(),
            'error' => $error ? SecretRedactor::truncate($error, 1000) : null,
            'sent_at' => $status === 'sent' ? now() : null,
        ]);
    }
}
