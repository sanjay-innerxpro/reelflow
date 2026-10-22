<?php

namespace App\Console\Commands;

use App\Services\Scheduling\PostDispatcher;
use Illuminate\Console\Command;

class DispatchDuePosts extends Command
{
    protected $signature = 'reels:dispatch';

    protected $description = 'Claim due scheduled posts and queue publishing jobs';

    public function handle(PostDispatcher $dispatcher): int
    {
        $count = $dispatcher->dispatchDue();
        $this->info("Dispatched {$count} post(s).");

        return self::SUCCESS;
    }
}
