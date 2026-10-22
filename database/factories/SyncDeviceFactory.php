<?php

namespace Database\Factories;

use App\Enums\DeviceStatus;
use App\Models\SyncDevice;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<SyncDevice>
 */
class SyncDeviceFactory extends Factory
{
    public function definition(): array
    {
        return [
            'device_uuid' => (string) Str::uuid(),
            'name' => fake()->word().' PC',
            'token_hash' => SyncDevice::hashToken('rfd_'.Str::random(48)),
            'token_hint' => Str::random(6),
            'status' => DeviceStatus::Active,
            'last_seen_at' => now(),
        ];
    }
}
