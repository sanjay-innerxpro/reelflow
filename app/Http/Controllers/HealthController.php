<?php

namespace App\Http\Controllers;

use App\Support\SystemCheck;
use Illuminate\Http\JsonResponse;

/**
 * For free uptime monitors (e.g. UptimeRobot): 200 when the database,
 * cache, scheduler and workers are alive, 503 otherwise. Reveals no
 * configuration or credentials.
 */
class HealthController extends Controller
{
    public function __invoke(SystemCheck $system): JsonResponse
    {
        $checks = $system->runtime();
        $healthy = SystemCheck::overall($checks) === SystemCheck::OK;

        return response()->json([
            'status' => $healthy ? 'ok' : 'degraded',
            'checks' => array_map(fn ($c) => $c['status'], $checks),
        ], $healthy ? 200 : 503)->header('Cache-Control', 'no-store');
    }
}
