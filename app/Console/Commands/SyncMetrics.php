<?php

namespace App\Console\Commands;

use App\Services\Analytics\MetricsService;
use App\Support\Tenancy\Tenant;
use Illuminate\Console\Command;

class SyncMetrics extends Command
{
    protected $signature = 'reels:sync-metrics {--days=30}';

    protected $description = 'Collect available performance metrics for recent posts';

    public function handle(MetricsService $metrics): int
    {
        $count = 0;
        Tenant::each(function () use ($metrics, &$count) {
            $count += $metrics->syncRecent((int) $this->option('days'));
        });
        $this->info("Synced metrics for {$count} post(s).");

        return self::SUCCESS;
    }
}
