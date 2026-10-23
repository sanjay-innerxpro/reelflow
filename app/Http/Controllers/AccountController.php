<?php

namespace App\Http\Controllers;

use App\Support\ActivityLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

class AccountController extends Controller
{
    public function edit(): View
    {
        return view('account');
    }

    public function updatePassword(Request $request, ActivityLogger $activity): RedirectResponse
    {
        $data = $request->validate([
            'current_password' => ['required', 'current_password'],
            'password' => ['required', 'confirmed', Password::min(12)],
        ]);

        $user = $request->user();
        $user->forceFill(['password' => Hash::make($data['password'])])->save();

        // Sign out every other browser that used the old password.
        Auth::logoutOtherDevices($data['password']);
        $activity->log('auth.password_changed', $user, 'Password changed');

        return back()->with('status', 'Password changed. Other sessions were signed out.');
    }
}
