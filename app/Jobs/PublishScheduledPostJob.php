<?php

namespace App\Jobs;

use App\Services\Publishing\PublishingService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * One publishing run for a claimed post. Retries are scheduled by the
 * PublishingService (persisted next_attempt_at), not by the queue, so a
 * crashed job never blindly repeats a remote call.
 */
class PublishScheduledPostJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 3600;

    public bool $failOnTimeout = true;

    public function __construct(public readonly int $postId, public readonly string $claimToken)
    {
        $this->onConnection(config('queue.long_connection'));
    }

    public function handle(PublishingService $service): void
    {
        $service->run($this->postId, $this->claimToken);
    }
}
