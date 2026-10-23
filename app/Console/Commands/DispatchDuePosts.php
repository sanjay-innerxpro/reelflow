<?php

namespace App\Console\Commands;

use App\Services\Scheduling\PostDispatcher;
use App\Support\Tenancy\Tenant;
use Illuminate\Console\Command;

class DispatchDuePosts extends Command
{
    protected $signature = 'reels:dispatch';

    protected $description = 'Claim due scheduled posts and queue publishing jobs';

    public function handle(PostDispatcher $dispatcher): int
    {
        $count = 0;
        Tenant::each(function () use ($dispatcher, &$count) {
            $count += $dispatcher->dispatchDue();
        });
        $this->info("Dispatched {$count} post(s).");

        return self::SUCCESS;
    }
}
