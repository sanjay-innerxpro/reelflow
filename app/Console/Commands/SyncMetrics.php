<?php

namespace App\Console\Commands;

use App\Services\Analytics\MetricsService;
use Illuminate\Console\Command;

class SyncMetrics extends Command
{
    protected $signature = 'reels:sync-metrics {--days=30}';

    protected $description = 'Collect available performance metrics for recent posts';

    public function handle(MetricsService $metrics): int
    {
        $this->info('Synced metrics for '.$metrics->syncRecent((int) $this->option('days')).' post(s).');

        return self::SUCCESS;
    }
}
