<?php

namespace App\Console\Commands;

use App\Jobs\WorkerHeartbeatJob;
use Illuminate\Console\Command;

class Heartbeat extends Command
{
    protected $signature = 'reels:heartbeat';

    protected $description = 'Queue worker heartbeat jobs now (the scheduler also does this every 5 minutes)';

    public function handle(): int
    {
        WorkerHeartbeatJob::dispatch('default');
        WorkerHeartbeatJob::dispatch('long');
        $this->info('Heartbeat jobs queued; run reels:doctor in a few seconds.');

        return self::SUCCESS;
    }
}
