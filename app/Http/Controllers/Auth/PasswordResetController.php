<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\ActivityLogger;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\View\View;

/**
 * "Forgot password" emails and the set-password page, which also accepts
 * invitations (the "invites" broker: 7-day links).
 */
class PasswordResetController extends Controller
{
    public function request(): View
    {
        return view('auth.forgot-password');
    }

    public function email(Request $request): RedirectResponse
    {
        $request->validate(['email' => ['required', 'email']]);
        Password::broker('users')->sendResetLink($request->only('email'));

        // Same answer whether or not the address exists.
        return back()->with('status', 'If that email belongs to an account, a reset link is on its way.');
    }

    public function edit(Request $request, string $token): View
    {
        return view('auth.reset-password', [
            'token' => $token,
            'email' => (string) $request->query('email'),
            'invite' => $request->boolean('invite'),
        ]);
    }

    public function update(Request $request, ActivityLogger $activity): RedirectResponse
    {
        $data = $request->validate([
            'token' => ['required', 'string'],
            'email' => ['required', 'email'],
            'password' => ['required', 'confirmed', PasswordRule::min(12)],
            'invite' => ['nullable', Rule::in(['0', '1'])],
        ]);
        $broker = ($data['invite'] ?? '0') === '1' ? 'invites' : 'users';

        $status = Password::broker($broker)->reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function (User $user, string $password) {
                $user->forceFill(['password' => Hash::make($password), 'remember_token' => Str::random(60), 'email_verified_at' => $user->email_verified_at ?? now()])->save();
                event(new PasswordReset($user));
            },
        );

        if ($status !== Password::PASSWORD_RESET) {
            return back()->withInput($request->only('email'))->withErrors(['email' => __($status)]);
        }

        $activity->log($broker === 'invites' ? 'auth.invite_accepted' : 'auth.password_reset', User::where('email', $data['email'])->first(), 'Password set');

        return redirect()->route('login')->with('status', 'Password saved. Sign in with your new password.');
    }

    /**
     * Link for an invited user to set their first password.
     */
    public static function inviteUrl(User $user): string
    {
        $token = Password::broker('invites')->createToken($user);

        return route('password.reset', ['token' => $token, 'email' => $user->email, 'invite' => 1]);
    }
}
