<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Laravel\Horizon\Contracts\MasterSupervisorRepository;
use Throwable;

/**
 * Lightweight queue health for the dashboard. Uses Horizon when available.
 */
class QueueHealth
{
    public const QUEUES = [
        'redis' => ['default', 'notifications', 'media', 'ai'],
        'long' => ['ingest', 'publish-facebook', 'publish-instagram', 'publish-youtube'],
    ];

    /**
     * @return array{horizon: string, failed_jobs: int, sizes: array<string, int|null>}
     */
    public function summary(): array
    {
        $horizon = config('reelflow.queue_mode') === 'cron' ? 'cron mode' : $this->horizonStatus();

        $sizes = [];
        foreach (self::QUEUES['redis'] as $queue) {
            $sizes[$queue] = rescue(fn () => Queue::connection(config('queue.default'))->size($queue), null, false);
        }
        foreach (self::QUEUES['long'] as $queue) {
            $sizes[$queue] = rescue(fn () => Queue::connection(config('queue.long_connection'))->size($queue), null, false);
        }

        return [
            'horizon' => $horizon,
            'failed_jobs' => (int) rescue(fn () => DB::table('failed_jobs')->count(), 0, false),
            'sizes' => $sizes,
        ];
    }

    private function horizonStatus(): string
    {
        try {
            $masters = app(MasterSupervisorRepository::class)->all();

            return $masters === [] ? 'inactive' : (collect($masters)->contains(fn ($m) => $m->status === 'paused') ? 'paused' : 'running');
        } catch (Throwable) {
            return 'unavailable';
        }
    }
}
