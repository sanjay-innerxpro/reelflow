<?php

use App\Jobs\WorkerHeartbeatJob;
use App\Support\SystemCheck;
use Illuminate\Support\Facades\Schedule;

/*
| The cloud server runs everything below; the local computer can be off.
| Cron: * * * * * cd /path && php artisan schedule:run >> /dev/null 2>&1
*/

// Heartbeats: /health and reels:doctor report a stopped cron or stopped workers.
Schedule::call(fn () => SystemCheck::beat(SystemCheck::SCHEDULER_KEY))->everyMinute()->name('scheduler-heartbeat');
Schedule::job(new WorkerHeartbeatJob('default'))->everyFiveMinutes()->name('worker-heartbeat');
Schedule::job(new WorkerHeartbeatJob('long'))->everyFiveMinutes()->name('worker-heartbeat-long');

Schedule::command('reels:dispatch')->everyMinute()->withoutOverlapping(10)->onOneServer();
Schedule::command('reels:plan')->everyFifteenMinutes()->withoutOverlapping(30)->onOneServer();
Schedule::command('reels:import-drive')->everyFiveMinutes()->withoutOverlapping(30)->runInBackground()->onOneServer();
Schedule::command('reels:check-devices')->everyTenMinutes()->onOneServer();
Schedule::command('reels:check-accounts')->dailyAt('02:30')->onOneServer();
Schedule::command('reels:sync-metrics')->everySixHours()->withoutOverlapping(120)->onOneServer();
Schedule::command('reels:cleanup')->dailyAt('03:30')->onOneServer();
if (config('reelflow.queue_mode') === 'cron') {
    // Shared hosting: no daemons allowed. Each minute start one worker per
    // lane; it exits when its queue is empty (or after ~50s of picking up
    // work). The overlap lock is short on purpose: the host may kill a
    // worker without releasing it, and a second worker during a long upload
    // is safe (posts are claimed atomically) and keeps other users' posts moving.
    Schedule::command('queue:work '.config('queue.default').' --queue=notifications,default,media,ai --stop-when-empty --max-time=50 --tries=3 --timeout=900')
        ->everyMinute()->withoutOverlapping(10)->runInBackground()->name('queue-worker-general');
    Schedule::command('queue:work '.config('queue.long_connection').' --queue=ingest,publish-youtube,publish-instagram,publish-facebook --stop-when-empty --max-time=50 --tries=1 --timeout=3600')
        ->everyMinute()->withoutOverlapping(10)->runInBackground()->name('queue-worker-publishing');
} else {
    Schedule::command('horizon:snapshot')->everyFiveMinutes();
}
Schedule::command('queue:prune-failed --hours=720')->daily();

// Each workspace has its own summary time; the command sends once per day after it.
Schedule::command('reels:daily-summary')->everyFifteenMinutes()->withoutOverlapping(10)->onOneServer();
