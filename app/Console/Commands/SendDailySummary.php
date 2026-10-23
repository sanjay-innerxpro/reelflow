<?php

namespace App\Console\Commands;

use App\Models\Workspace;
use App\Services\Notifications\NotificationService;
use App\Support\DisplayTime;
use App\Support\Settings;
use App\Support\Tenancy\Tenant;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class SendDailySummary extends Command
{
    protected $signature = 'reels:daily-summary {--force : Send now, ignoring each workspace\'s summary time}';

    protected $description = 'Email each workspace its daily publishing summary once its summary time has passed';

    public function handle(NotificationService $notifications, Settings $settings): int
    {
        Tenant::each(function (Workspace $workspace) use ($notifications, $settings) {
            $now = now(DisplayTime::timezone());
            $time = (string) $settings->get('notifications.daily_summary_time');
            $due = preg_match('/^\d{2}:\d{2}$/', $time) ? $now->copy()->setTimeFromTimeString($time) : $now->copy()->setTime(21, 30);
            $sentKey = "reelflow:summary:{$workspace->id}:{$now->toDateString()}";

            if (! $this->option('force') && ($now->lt($due) || Cache::has($sentKey))) {
                return;
            }

            Cache::put($sentKey, true, now()->addDays(2));
            $notifications->dailySummary();
        });

        return self::SUCCESS;
    }
}
