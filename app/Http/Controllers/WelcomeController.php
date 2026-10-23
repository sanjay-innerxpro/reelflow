<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

/**
 * Public home page; signed-in users go straight to their dashboard.
 */
class WelcomeController extends Controller
{
    public function __invoke(): View|RedirectResponse
    {
        if (auth()->check()) {
            return redirect()->route('dashboard');
        }

        return view('welcome', ['contact' => config('reelflow.admin_email')]);
    }
}
