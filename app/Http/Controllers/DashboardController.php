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
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __invoke(QueueHealth $queues): View
    {
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
            'accounts' => SocialAccount::with('postingRule')->orderBy('platform')->get(),
            'devices' => SyncDevice::where('status', 'active')->orderByDesc('last_seen_at')->get(),
            'upcoming' => ScheduledPost::with(['video', 'socialAccount'])->where('status', PostStatus::Scheduled->value)
                ->orderBy('scheduled_at')->limit(8)->get(),
            'activity' => ActivityLog::latest('created_at')->limit(12)->get(),
            'queues' => $queues->summary(),
        ]);
    }
}
