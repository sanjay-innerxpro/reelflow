<?php

use App\Support\Settings;
use Illuminate\Support\Facades\Schedule;

/*
| The cloud server runs everything below; the local computer can be off.
| Cron: * * * * * cd /path && php artisan schedule:run >> /dev/null 2>&1
*/

Schedule::command('reels:dispatch')->everyMinute()->withoutOverlapping(10)->onOneServer();
Schedule::command('reels:plan')->everyFifteenMinutes()->withoutOverlapping(30)->onOneServer();
Schedule::command('reels:check-devices')->everyTenMinutes()->onOneServer();
Schedule::command('reels:check-accounts')->dailyAt('02:30')->onOneServer();
Schedule::command('reels:sync-metrics')->everySixHours()->withoutOverlapping(120)->onOneServer();
Schedule::command('reels:cleanup')->dailyAt('03:30')->onOneServer();
Schedule::command('horizon:snapshot')->everyFiveMinutes();
Schedule::command('queue:prune-failed --hours=720')->daily();

$summaryTime = rescue(fn () => (string) app(Settings::class)->get('notifications.daily_summary_time'), '21:30', false);
Schedule::command('reels:daily-summary')
    ->dailyAt(preg_match('/^\d{2}:\d{2}$/', $summaryTime) ? $summaryTime : '21:30')
    ->timezone(config('reelflow.display_timezone'))
    ->onOneServer();
