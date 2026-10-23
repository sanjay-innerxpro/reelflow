<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\ResolvesWorkspace;
use App\Models\SyncDevice;
use App\Support\Tenancy\Tenant;
use Illuminate\Console\Command;

class CreateDevice extends Command
{
    use ResolvesWorkspace;

    protected $signature = 'reels:create-device {name} {--workspace= : Workspace ID (optional with one workspace)}';

    protected $description = 'Register a sync agent device and print its credential once';

    public function handle(): int
    {
        if (! $workspace = $this->workspace()) {
            return self::FAILURE;
        }
        [$device, $token] = Tenant::run($workspace, fn () => SyncDevice::issue((string) $this->argument('name')));
        $this->info("Device #{$device->id} \"{$device->name}\" created.");
        $this->warn('Store this token in the agent .env as REELFLOW_DEVICE_TOKEN. It will not be shown again:');
        $this->line($token);

        return self::SUCCESS;
    }
}
