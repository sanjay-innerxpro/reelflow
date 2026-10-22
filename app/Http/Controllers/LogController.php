<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class LogController extends Controller
{
    public function index(Request $request): View
    {
        $filters = $request->validate([
            'level' => ['nullable', Rule::in(['info', 'warning', 'error'])],
            'action' => ['nullable', 'string', 'max:80'],
        ]);

        return view('logs.index', [
            'logs' => ActivityLog::with('user')
                ->when($filters['level'] ?? null, fn ($q, $l) => $q->where('level', $l))
                ->when($filters['action'] ?? null, fn ($q, $a) => $q->where('action', 'like', str_replace(['%', '_'], ['\%', '\_'], $a).'%'))
                ->latest('created_at')->paginate(50)->withQueryString(),
            'filters' => $filters,
        ]);
    }

    public function failedJobs(): View
    {
        return view('logs.failed-jobs', [
            'jobs' => DB::table('failed_jobs')->latest('failed_at')->paginate(25),
        ]);
    }

    public function retryJob(string $uuid): RedirectResponse
    {
        Artisan::call('queue:retry', ['id' => [$uuid]]);

        return back()->with('status', 'Job pushed back onto its queue.');
    }

    public function forgetJob(string $uuid): RedirectResponse
    {
        Artisan::call('queue:forget', ['id' => $uuid]);

        return back()->with('status', 'Failed job removed.');
    }
}
