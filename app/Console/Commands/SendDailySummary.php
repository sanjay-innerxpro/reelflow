<?php

namespace App\Console\Commands;

use App\Services\Notifications\NotificationService;
use Illuminate\Console\Command;

class SendDailySummary extends Command
{
    protected $signature = 'reels:daily-summary';

    protected $description = 'Email the daily publishing summary';

    public function handle(NotificationService $notifications): int
    {
        $notifications->dailySummary();

        return self::SUCCESS;
    }
}
