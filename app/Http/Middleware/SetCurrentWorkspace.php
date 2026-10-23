<?php

namespace App\Http\Middleware;

use App\Support\Tenancy\Tenant;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Scopes every query of a signed-in request to the user's workspace. Runs
 * before route-model binding, so another workspace's IDs simply 404.
 */
class SetCurrentWorkspace
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $workspace = $user?->workspace;

        if ($user && (! $workspace || $workspace->isSuspended())) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();

            return redirect()->route('login')->withErrors(['email' => 'This account is suspended. Contact the administrator.']);
        }

        return Tenant::run($workspace, fn () => $next($request));
    }
}
