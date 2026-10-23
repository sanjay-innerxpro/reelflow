<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Support\ActivityLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Invite-only: there is no public registration. The administrator invites
 * users (Admin → Users) or creates them with `php artisan reels:create-user`.
 */
class LoginController extends Controller
{
    public function show(): View
    {
        return view('auth.login');
    }

    public function login(Request $request, ActivityLogger $activity): RedirectResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        if (! Auth::attempt($credentials, $request->boolean('remember'))) {
            $activity->log('auth.failed', null, 'Failed login for '.$credentials['email'], level: 'warning');
            throw ValidationException::withMessages(['email' => 'These credentials do not match our records.']);
        }

        $user = $request->user();
        if (! $user->workspace || $user->workspace->isSuspended()) {
            Auth::logout();
            $activity->log('auth.suspended', $user, 'Sign-in refused: workspace suspended', level: 'warning');
            throw ValidationException::withMessages(['email' => 'This account is suspended. Contact the administrator.']);
        }

        $request->session()->regenerate();
        $user->forceFill(['last_login_at' => now()])->save();
        $activity->log('auth.login', $user, 'Signed in');

        return redirect()->intended(route('dashboard'));
    }

    public function logout(Request $request): RedirectResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
