<?php

namespace Tests\Feature;

use App\Enums\Platform;
use App\Enums\PostStatus;
use App\Models\NotificationLog;
use App\Models\SyncDevice;
use App\Models\User;
use App\Notifications\DailySummaryNotification;
use App\Notifications\DeviceOfflineNotification;
use App\Notifications\PostOutcomeNotification;
use App\Notifications\TestEmailNotification;
use App\Services\Notifications\NotificationService;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;
use Tests\Support\BuildsFixtures;
use Tests\TestCase;

class NotificationTest extends TestCase
{
    use BuildsFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeInfrastructure();
    }

    public function test_failure_email_contains_required_details_without_secrets(): void
    {
        Notification::fake();
        [$post] = $this->duePost(Platform::Instagram, [
            'status' => PostStatus::Failed, 'last_error_category' => 'invalid_video',
            'last_error_message' => 'Instagram could not process the video', 'attempt_count' => 2,
        ]);

        app(NotificationService::class)->postFailed($post);

        Notification::assertSentOnDemand(PostOutcomeNotification::class, function (PostOutcomeNotification $n, array $channels, AnonymousNotifiable $notifiable) use ($post) {
            $mail = $n->toMail($notifiable);
            $text = implode("\n", array_merge($mail->introLines, $mail->outroLines));

            return $notifiable->routes['mail'] === ['admin@example.test']
                && str_contains($mail->subject, 'Failed on Instagram Reels')
                && str_contains($text, $post->video->original_filename)
                && str_contains($text, 'Instagram could not process the video')
                && str_contains($text, 'Scheduled:')
                && ! str_contains($text, $post->socialAccount->access_token)
                && $mail->actionUrl === route('posts.show', $post);
        });
    }

    public function test_toggles_disable_notifications(): void
    {
        Notification::fake();
        app(Settings::class)->set(['notifications.on_success' => false]);
        [$post] = $this->duePost(Platform::Facebook, ['status' => PostStatus::Published, 'post_url' => 'https://facebook.com/reel/1']);

        app(NotificationService::class)->postPublished($post);

        Notification::assertNothingSent();
    }

    public function test_no_recipients_means_nothing_sent(): void
    {
        Notification::fake();
        app(Settings::class)->set(['notifications.recipients' => []]);
        [$post] = $this->duePost(Platform::Facebook, ['status' => PostStatus::Failed]);

        app(NotificationService::class)->postFailed($post);

        Notification::assertNothingSent();
    }

    public function test_offline_device_is_reported_once(): void
    {
        Notification::fake();
        $device = SyncDevice::factory()->create(['last_seen_at' => now()->subHours(2)]);
        SyncDevice::factory()->create(['last_seen_at' => now()]);

        $this->artisan('reels:check-devices')->assertSuccessful();
        $this->artisan('reels:check-devices')->assertSuccessful();

        Notification::assertSentOnDemandTimes(DeviceOfflineNotification::class, 1);
        $this->assertNotNull($device->fresh()->offline_notified_at);
    }

    public function test_daily_summary_counts_outcomes(): void
    {
        Notification::fake();
        $this->duePost(Platform::Facebook, ['status' => PostStatus::Published, 'published_at' => now(), 'post_url' => 'https://facebook.com/reel/9']);
        $this->duePost(Platform::YouTube, ['status' => PostStatus::Failed, 'last_error_message' => 'quota']);

        $this->artisan('reels:daily-summary', ['--force' => true])->assertSuccessful();

        Notification::assertSentOnDemand(DailySummaryNotification::class, function (DailySummaryNotification $n) {
            return $n->summary['published'] === 1 && $n->summary['failed'] === 1 && count($n->summary['links']) === 1;
        });
    }

    public function test_sent_mail_is_logged(): void
    {
        [$post] = $this->duePost(Platform::Facebook, ['status' => PostStatus::Published, 'post_url' => 'https://facebook.com/reel/1']);

        app(NotificationService::class)->postPublished($post);

        $log = NotificationLog::first();
        $this->assertSame('post_published', $log->type);
        $this->assertSame('sent', $log->status);
        $this->assertSame('admin@example.test', $log->recipient);
        $this->assertSame($post->id, $log->related_id);
    }

    public function test_test_email_button(): void
    {
        Notification::fake();
        $this->actingAs(User::factory()->create())
            ->post(route('settings.email.test'), ['email' => 'me@example.test'])
            ->assertSessionHas('status');

        Notification::assertSentOnDemand(TestEmailNotification::class);
    }

    public function test_smtp_validation_reports_non_smtp_mailer(): void
    {
        $this->actingAs(User::factory()->create())->post(route('settings.email.validate'))->assertForbidden();

        $this->actingAs(User::factory()->admin()->create())
            ->post(route('settings.email.validate'))
            ->assertSessionHasErrors('smtp');
    }
}
