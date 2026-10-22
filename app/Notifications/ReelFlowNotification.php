<?php

namespace App\Notifications;

use App\Models\NotificationLog;
use App\Support\SecretRedactor;
use App\Support\Settings;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\Notification;

/**
 * Base for all emails. Queued on the "notifications" queue; never includes
 * access tokens or raw API responses.
 */
abstract class ReelFlowNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [60, 300];

    public function __construct()
    {
        $this->onQueue('notifications');
    }

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    abstract public function type(): string;

    public function related(): ?Model
    {
        return null;
    }

    abstract public function subject(): string;

    /**
     * Called when the queued mail job has exhausted its retries.
     */
    public function failed(\Throwable $e): void
    {
        $related = $this->related();

        NotificationLog::create([
            'type' => $this->type(),
            'channel' => 'mail',
            'recipient' => mb_substr(implode(', ', app(Settings::class)->recipients()), 0, 255),
            'subject' => mb_substr($this->subject(), 0, 255),
            'status' => 'failed',
            'related_type' => $related?->getMorphClass(),
            'related_id' => $related?->getKey(),
            'error' => SecretRedactor::truncate($e->getMessage(), 1000),
        ]);
    }
}
