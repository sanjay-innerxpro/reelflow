<?php

namespace App\Http\Controllers;

use App\Enums\PostStatus;
use App\Enums\VideoStatus;
use App\Models\ActivityLog;
use App\Models\ScheduledPost;
use App\Models\SocialAccount;
use App\Models\SyncDevice;
use App\Models\Video;
use App\Support\DisplayTime;
use App\Support\QueueHealth;
use App\Support\SystemCheck;
use App\Support\Tenancy\Tenant;
use Illuminate\Support\Facades\Cache;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __invoke(QueueHealth $queues, SystemCheck $system): View
    {
        $admin = auth()->user()->isAdmin();
        $checks = $admin ? Cache::remember('reelflow:dashboard:checks:'.Tenant::id(), 60, fn () => $system->all()) : [];
        $tz = DisplayTime::timezone();
        $todayStart = now($tz)->startOfDay()->utc();

        return view('dashboard', [
            'stats' => [
                'videos' => Video::count(),
                'awaiting' => Video::whereIn('status', [VideoStatus::Discovered->value, VideoStatus::Uploading->value, VideoStatus::Uploaded->value, VideoStatus::Processing->value])->count(),
                'scheduled' => ScheduledPost::where('status', PostStatus::Scheduled->value)->count(),
                'published_today' => ScheduledPost::where('status', PostStatus::Published->value)->where('published_at', '>=', $todayStart)->count(),
                'failed' => ScheduledPost::where('status', PostStatus::Failed->value)->count(),
                'retrying' => ScheduledPost::whereIn('status', [PostStatus::Retrying->value, PostStatus::Processing->value, PostStatus::Publishing->value])->count(),
            ],
            'accounts' => $accounts = SocialAccount::with('postingRule')->orderBy('platform')->get(),
            'onboarding' => [
                'account' => $accounts->isNotEmpty(),
                'videos' => Video::exists(),
                'autopost' => $accounts->contains(fn (SocialAccount $a) => $a->postingRule?->enabled),
            ],
            'devices' => SyncDevice::where('status', 'active')->orderByDesc('last_seen_at')->get(),
            'ready' => Video::where('status', VideoStatus::Ready->value)->count(),
            'upcoming' => ScheduledPost::with(['video', 'socialAccount'])->where('status', PostStatus::Scheduled->value)
                ->orderBy('scheduled_at')->limit(8)->get(),
            // Routine status changes and sign-ins stay in the full activity log.
            'activity' => ActivityLog::whereNotIn('action', ['video.status_changed', 'scheduledpost.status_changed', 'auth.login'])->latest('created_at')->limit(10)->get(),
            'queues' => $admin ? $queues->summary() : null,
            'setup' => $admin ? array_filter($checks, fn ($check) => $check['status'] !== SystemCheck::OK) : [],
        ]);
    }
}
