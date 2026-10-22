<?php

namespace App\Console\Commands;

use App\Models\SyncDevice;
use Illuminate\Console\Command;

class CreateDevice extends Command
{
    protected $signature = 'reels:create-device {name}';

    protected $description = 'Register a sync agent device and print its credential once';

    public function handle(): int
    {
        [$device, $token] = SyncDevice::issue((string) $this->argument('name'));
        $this->info("Device #{$device->id} \"{$device->name}\" created.");
        $this->warn('Store this token in the agent .env as REELFLOW_DEVICE_TOKEN. It will not be shown again:');
        $this->line($token);

        return self::SUCCESS;
    }
}
