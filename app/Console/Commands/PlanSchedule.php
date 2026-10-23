<?php

namespace App\Console\Commands;

use App\Services\Scheduling\SchedulePlanner;
use App\Support\Tenancy\Tenant;
use Illuminate\Console\Command;

class PlanSchedule extends Command
{
    protected $signature = 'reels:plan';

    protected $description = 'Fill upcoming posting slots from the posting rules';

    public function handle(SchedulePlanner $planner): int
    {
        $count = 0;
        Tenant::each(function () use ($planner, &$count) {
            $count += $planner->planAll();
        });
        $this->info("Scheduled {$count} new post(s).");

        return self::SUCCESS;
    }
}
