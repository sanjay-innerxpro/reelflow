<?php

namespace App\Services\Notifications;

use App\Enums\AccountStatus;
use App\Enums\Platform;
use App\Enums\PostStatus;
use App\Enums\VideoStatus;
use App\Models\ScheduledPost;
use App\Models\SocialAccount;
use App\Models\SyncDevice;
use App\Models\Video;
use App\Notifications\AccountProblemNotification;
use App\Notifications\DailySummaryNotification;
use App\Notifications\DeviceOfflineNotification;
use App\Notifications\PostOutcomeNotification;
use App\Notifications\ReelFlowNotification;
use App\Notifications\TestEmailNotification;
use App\Notifications\UploadFailuresNotification;
use App\Support\DisplayTime;
use App\Support\Settings;
use Illuminate\Support\Facades\Notification;

class NotificationService
{
    public function __construct(private readonly Settings $settings) {}

    public function postPublished(ScheduledPost $post): void
    {
        $this->sendIf('notifications.on_success', new PostOutcomeNotification($post));
    }

    public function postFailed(ScheduledPost $post): void
    {
        $this->sendIf('notifications.on_failure', new PostOutcomeNotification($post));
    }

    public function accountProblem(SocialAccount $account): void
    {
        $this->sendIf('notifications.on_auth_problem', new AccountProblemNotification($account));
    }

    public function deviceOffline(SyncDevice $device): void
    {
        $this->sendIf('notifications.on_device_offline', new DeviceOfflineNotification($device));
    }

    public function repeatedUploadFailures(Video $video): void
    {
        $this->sendIf('notifications.on_upload_failures', new UploadFailuresNotification($video));
    }

    public function dailySummary(): void
    {
        $this->sendIf('notifications.daily_summary', new DailySummaryNotification($this->buildSummary()));
    }

    /**
     * Send synchronously so the settings page can show SMTP errors directly.
     */
    public function sendTest(string $email): void
    {
        Notification::route('mail', $email)->notifyNow(new TestEmailNotification);
    }

    /**
     * @return array<string, mixed>
     */
    public function buildSummary(): array
    {
        $tz = DisplayTime::timezone();
        $start = now($tz)->startOfDay()->utc();
        $end = now($tz)->endOfDay()->utc();

        $today = ScheduledPost::with(['video', 'socialAccount'])
            ->where(fn ($q) => $q->whereBetween('published_at', [$start, $end])
                ->orWhere(fn ($q) => $q->whereIn('status', [PostStatus::Failed->value, PostStatus::Retrying->value])->whereBetween('updated_at', [$start, $end])))
            ->get();

        $byPlatform = [];
        foreach (Platform::cases() as $platform) {
            $rows = $today->where('platform', $platform);
            $byPlatform[$platform->label()] = [
                'published' => $rows->where('status', PostStatus::Published)->count(),
                'failed' => $rows->where('status', PostStatus::Failed)->count(),
            ];
        }

        return [
            'date' => now($tz)->format('d M Y'),
            'published' => $today->where('status', PostStatus::Published)->count(),
            'failed' => $today->where('status', PostStatus::Failed)->count(),
            'retrying' => $today->where('status', PostStatus::Retrying)->count(),
            'upcoming' => ScheduledPost::where('status', PostStatus::Scheduled->value)->whereBetween('scheduled_at', [now(), now()->addDay()])->count(),
            'ready_videos' => Video::where('status', VideoStatus::Ready->value)->count(),
            'new_videos' => Video::whereBetween('created_at', [$start, $end])->count(),
            'by_platform' => $byPlatform,
            'failures' => $today->where('status', PostStatus::Failed)
                ->map(fn ($p) => "{$p->platform->label()} — {$p->video->original_filename}: {$p->last_error_message}")->values()->all(),
            'links' => $today->where('status', PostStatus::Published)->filter(fn ($p) => $p->post_url)
                ->map(fn ($p) => "{$p->platform->label()} — {$p->video->original_filename}: {$p->post_url}")->values()->all(),
            'account_problems' => SocialAccount::whereIn('status', [AccountStatus::Expired->value, AccountStatus::Revoked->value, AccountStatus::Ineligible->value])
                ->pluck('name')->all(),
        ];
    }

    private function sendIf(string $setting, ReelFlowNotification $notification): void
    {
        if (! $this->settings->bool($setting)) {
            return;
        }

        $recipients = $this->settings->recipients();
        if ($recipients === []) {
            return;
        }

        Notification::route('mail', $recipients)->notify($notification);
    }
}
