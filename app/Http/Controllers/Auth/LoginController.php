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
 * Private dashboard: there is no public registration. Users are created with
 * `php artisan reels:create-user`.
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

        $request->session()->regenerate();
        $activity->log('auth.login', $request->user(), 'Signed in');

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
