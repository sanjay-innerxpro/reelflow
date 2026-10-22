<?php

namespace App\Http\Controllers;

use App\Models\SyncDevice;
use App\Support\ActivityLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class DeviceController extends Controller
{
    public function index(): View
    {
        return view('devices.index', [
            'devices' => SyncDevice::withCount('videos')->latest()->get(),
            'offlineAfter' => config('reelflow.devices.offline_after_minutes'),
            'apiUrl' => url('/'),
        ]);
    }

    public function store(Request $request, ActivityLogger $activity): RedirectResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:100']]);
        [$device, $token] = SyncDevice::issue($data['name'], $request->user()->id);
        $activity->log('device.created', $device, "Device {$device->name} registered");

        // Shown exactly once; only the hash is stored.
        return redirect()->route('devices.index')->with('new_token', $token)->with('new_device', $device->name);
    }

    public function revoke(SyncDevice $device, ActivityLogger $activity): RedirectResponse
    {
        Gate::authorize('delete', $device);
        $device->revoke();
        $activity->log('device.revoked', $device, "Device {$device->name} revoked");

        return back()->with('status', "{$device->name} revoked. Its token no longer works.");
    }
}
