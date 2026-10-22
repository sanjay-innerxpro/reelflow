<?php

namespace App\Http\Middleware;

use App\Models\SyncDevice;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authenticates the local sync agent with its revocable bearer credential.
 * Devices can only use the sync endpoints; they have no dashboard access.
 */
class AuthenticateDevice
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = (string) $request->bearerToken();
        if ($token === '' || ! str_starts_with($token, config('reelflow.devices.token_prefix'))) {
            return response()->json(['error' => 'unauthenticated', 'message' => 'Missing or malformed device token.'], 401);
        }

        $device = SyncDevice::findByToken($token);
        if (! $device) {
            return response()->json(['error' => 'unauthenticated', 'message' => 'Unknown device token.'], 401);
        }
        if (! $device->isActive()) {
            return response()->json(['error' => 'device_revoked', 'message' => 'This device has been revoked.'], 403);
        }

        $request->attributes->set('device', $device);

        // Cheap presence tracking; full details come with the heartbeat.
        if (! $device->last_seen_at || $device->last_seen_at->lt(now()->subMinute())) {
            $device->forceFill(['last_seen_at' => now(), 'last_ip' => $request->ip(), 'offline_notified_at' => null])->save();
        }

        return $next($request);
    }
}
