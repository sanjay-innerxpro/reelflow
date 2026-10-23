<?php

namespace App\Console\Commands;

use App\Enums\DeviceStatus;
use App\Models\SyncDevice;
use App\Services\Notifications\NotificationService;
use App\Support\ActivityLogger;
use App\Support\Tenancy\Tenant;
use Illuminate\Console\Command;

class CheckSyncDevices extends Command
{
    protected $signature = 'reels:check-devices';

    protected $description = 'Notify when a sync agent has been offline too long';

    public function handle(NotificationService $notifications, ActivityLogger $activity): int
    {
        $cutoff = now()->subMinutes(config('reelflow.devices.offline_after_minutes'));

        Tenant::each(fn () => SyncDevice::where('status', DeviceStatus::Active->value)
            ->whereNotNull('last_seen_at')
            ->where('last_seen_at', '<', $cutoff)
            ->whereNull('offline_notified_at')
            ->get()
            ->each(function (SyncDevice $device) use ($notifications, $activity) {
                $device->forceFill(['offline_notified_at' => now()])->save();
                $activity->log('device.offline', $device, "{$device->name} has not checked in since {$device->last_seen_at}", level: 'warning');
                $notifications->deviceOffline($device);
            }));

        return self::SUCCESS;
    }
}
