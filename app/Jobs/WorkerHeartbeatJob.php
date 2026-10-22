<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;

/**
 * Queued every few minutes by the scheduler. If workers are down the
 * timestamp goes stale and /health reports it (works with Horizon or plain
 * queue:work).
 */
class WorkerHeartbeatJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public readonly string $lane)
    {
        if ($lane === 'long') {
            $this->onConnection(config('queue.long_connection'))->onQueue('ingest');
        } else {
            $this->onQueue('default');
        }
    }

    public function handle(): void
    {
        Cache::forever('reelflow:heartbeat:worker:'.$this->lane, now()->toIso8601String());
    }
}
