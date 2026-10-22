<?php

namespace Tests\Feature;

use App\Enums\ContentStatus;
use App\Enums\Platform;
use App\Enums\PostStatus;
use App\Enums\PublishStage;
use App\Jobs\PublishScheduledPostJob;
use App\Models\ScheduledPost;
use App\Models\Video;
use App\Services\Publishing\PublishingService;
use App\Services\Scheduling\PostDispatcher;
use App\Services\Scheduling\SchedulePlanner;
use App\Services\Scheduling\ScheduleService;
use App\Support\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Tests\Support\BuildsFixtures;
use Tests\TestCase;

class SchedulingTest extends TestCase
{
    use BuildsFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeInfrastructure();
        // 07:00 IST on a Wednesday: all five default slots are still ahead today.
        $this->travelTo(CarbonImmutable::parse('2026-10-21 07:00', 'Asia/Kolkata'));
        config(['reelflow.scheduling.planning_horizon_days' => 0]);
    }

    /**
     * @return array<int, Video>
     */
    private function readyVideos(int $count, Platform ...$platforms): array
    {
        $videos = [];
        for ($i = 0; $i < $count; $i++) {
            $videos[] = $video = $this->readyVideo(['uploaded_at' => now()->subMinutes(100 - $i)], false);
            foreach ($platforms as $platform) {
                $this->contentFor($video, $platform);
            }
        }

        return $videos;
    }

    public function test_planner_fills_daily_slots_in_utc_with_oldest_videos_first(): void
    {
        $videos = $this->readyVideos(7, Platform::Facebook);
        $account = $this->account(Platform::Facebook);

        $created = app(SchedulePlanner::class)->planAccount($account);

        $this->assertSame(5, $created);
        $posts = ScheduledPost::orderBy('scheduled_at')->get();
        $this->assertSame(['03:00', '05:30', '08:00', '11:00', '14:30'], $posts->map(fn ($p) => $p->scheduled_at->format('H:i'))->all());
        $this->assertSame(array_map(fn ($v) => $v->id, array_slice($videos, 0, 5)), $posts->pluck('video_id')->all());
        $this->assertTrue($posts->every(fn ($p) => $p->status === PostStatus::Scheduled && $p->platform === Platform::Facebook));
    }

    public function test_planner_is_idempotent_and_respects_daily_limit(): void
    {
        $this->readyVideos(10, Platform::Facebook);
        $account = $this->account(Platform::Facebook, [], ['daily_limit' => 3]);
        $planner = app(SchedulePlanner::class);

        $this->assertSame(3, $planner->planAccount($account));
        $this->assertSame(0, $planner->planAccount($account));
        $this->assertSame(3, ScheduledPost::count());
    }

    public function test_concurrent_planner_runs_do_not_double_book(): void
    {
        $this->readyVideos(5, Platform::Facebook);
        $account = $this->account(Platform::Facebook);

        $lock = Cache::lock('planner:account:'.$account->id, 120);
        $this->assertTrue($lock->get());
        $this->assertSame(0, app(SchedulePlanner::class)->planAccount($account), 'A second runner backs off while the lock is held');
        $lock->release();

        $this->assertSame(5, app(SchedulePlanner::class)->planAccount($account));
    }

    public function test_each_platform_gets_its_own_post_record(): void
    {
        $this->readyVideos(1, Platform::Facebook, Platform::Instagram, Platform::YouTube);
        $this->account(Platform::Facebook);
        $this->account(Platform::Instagram);
        $this->account(Platform::YouTube);

        app(SchedulePlanner::class)->planAll();

        $this->assertSame(3, ScheduledPost::count());
        $this->assertEqualsCanonicalizing(['facebook', 'instagram', 'youtube'], ScheduledPost::pluck('platform')->map->value->all());
    }

    public function test_without_cross_posting_each_video_goes_to_one_destination(): void
    {
        app(Settings::class)->set(['publishing.cross_post_all' => false]);
        $this->readyVideos(2, Platform::Facebook, Platform::Instagram);
        $this->account(Platform::Facebook);
        $this->account(Platform::Instagram);

        app(SchedulePlanner::class)->planAll();

        $this->assertSame(2, ScheduledPost::count());
        $this->assertSame(2, ScheduledPost::distinct('video_id')->count('video_id'));
    }

    public function test_ineligible_or_unapproved_videos_are_skipped(): void
    {
        [$good, $lowConfidence, $landscape] = $this->readyVideos(3, Platform::Facebook);
        $lowConfidence->aiContents()->update(['validation_status' => ContentStatus::LowConfidence]);
        $landscape->update(['width' => 1920, 'height' => 1080]);
        $this->readyVideo(['status' => 'processing'], false);
        $account = $this->account(Platform::Facebook);

        app(SchedulePlanner::class)->planAccount($account);

        $this->assertSame([$good->id], ScheduledPost::pluck('video_id')->all());
    }

    public function test_approval_mode_only_schedules_approved_captions(): void
    {
        app(Settings::class)->set(['publishing.require_approval' => true]);
        [$a, $b] = $this->readyVideos(2, Platform::Facebook);
        $b->aiContents()->update(['validation_status' => ContentStatus::Approved]);

        app(SchedulePlanner::class)->planAccount($this->account(Platform::Facebook));

        $this->assertSame([$b->id], ScheduledPost::pluck('video_id')->all());
    }

    public function test_reuse_only_when_allowed(): void
    {
        [$video] = $this->readyVideos(1, Platform::Facebook);
        $account = $this->account(Platform::Facebook, [], ['allow_reuse' => false]);
        ScheduledPost::factory()->create(['video_id' => $video->id, 'social_account_id' => $account->id, 'platform' => Platform::Facebook, 'status' => PostStatus::Published, 'scheduled_at' => now()->subDay()]);

        $this->assertSame(0, app(SchedulePlanner::class)->planAccount($account));

        $account->postingRule->update(['allow_reuse' => true]);
        $this->assertSame(1, app(SchedulePlanner::class)->planAccount($account->fresh('postingRule')));
        $this->assertSame(1, ScheduledPost::where('status', 'scheduled')->value('reuse_sequence'));
    }

    public function test_period_limit_is_respected(): void
    {
        $this->readyVideos(5, Platform::Facebook);
        $account = $this->account(Platform::Facebook, [], ['max_posts_per_period' => 2, 'period_hours' => 24]);

        $this->assertSame(2, app(SchedulePlanner::class)->planAccount($account));
    }

    public function test_dispatcher_claims_each_due_post_exactly_once(): void
    {
        Queue::fake();
        [$due] = $this->duePost(Platform::Facebook, ['scheduled_at' => now()->subMinute()]);
        [$future] = $this->duePost(Platform::Facebook, ['scheduled_at' => now()->addHour()]);

        $dispatcher = app(PostDispatcher::class);
        $this->assertSame(1, $dispatcher->dispatchDue());
        $this->assertSame(0, $dispatcher->dispatchDue(), 'Already claimed');

        Queue::assertPushed(PublishScheduledPostJob::class, 1);
        Queue::assertPushedOn('publish-facebook', PublishScheduledPostJob::class);
        $this->assertNotNull($due->fresh()->claim_token);
        $this->assertNull($future->fresh()->claim_token);
    }

    public function test_competing_claims_have_a_single_winner(): void
    {
        [$post] = $this->duePost(Platform::Facebook);
        $dispatcher = app(PostDispatcher::class);

        $first = $dispatcher->claim(ScheduledPost::find($post->id));
        $second = $dispatcher->claim(ScheduledPost::find($post->id));

        $this->assertNotNull($first);
        $this->assertNull($second);
    }

    public function test_stale_job_with_wrong_claim_token_does_nothing(): void
    {
        [$post] = $this->duePost(Platform::Facebook);
        $token = app(PostDispatcher::class)->claim($post);

        (new PublishScheduledPostJob($post->id, 'not-'.$token))->handle(app(PublishingService::class));

        $this->assertSame(PostStatus::Scheduled, $post->fresh()->status);
        $this->assertSame(0, $post->fresh()->attempt_count);
    }

    public function test_paused_accounts_and_global_pause_stop_dispatch(): void
    {
        Queue::fake();
        [$post, , $account] = $this->duePost(Platform::Facebook);

        $account->update(['paused_until' => now()->addHour()]);
        $this->assertSame(0, app(PostDispatcher::class)->dispatchDue());

        $account->update(['paused_until' => null]);
        app(Settings::class)->set(['publishing.paused' => true]);
        $this->assertSame(0, app(PostDispatcher::class)->dispatchDue());

        app(Settings::class)->set(['publishing.paused' => false]);
        $this->assertSame(1, app(PostDispatcher::class)->dispatchDue());
    }

    public function test_crashed_worker_post_is_recovered_for_reconciliation(): void
    {
        [$post] = $this->duePost(Platform::Facebook, [
            'status' => PostStatus::Publishing, 'stage' => PublishStage::PublishRequested,
            'claim_token' => 'dead', 'claimed_until' => now()->subMinute(),
        ]);
        ScheduledPost::whereKey($post->id)->update(['updated_at' => now()->subHours(2)]);

        $this->assertSame(1, app(PostDispatcher::class)->recoverStaleClaims());

        $post->refresh();
        $this->assertSame(PostStatus::Retrying, $post->status);
        $this->assertSame(PublishStage::PublishRequested, $post->stage, 'Stage kept so the next run reconciles');
        $this->assertNull($post->claim_token);
    }

    public function test_manual_scheduling_prevents_accidental_duplicates(): void
    {
        [$video] = $this->readyVideos(1, Platform::Facebook);
        $account = $this->account(Platform::Facebook);
        $service = app(ScheduleService::class);

        $post = $service->schedule($video, $account, CarbonImmutable::parse('2026-10-21 18:00', 'Asia/Kolkata'));
        $this->assertSame('12:30', $post->scheduled_at->format('H:i'), 'Stored in UTC');

        try {
            $service->schedule($video, $account, now()->addDay());
            $this->fail('Duplicate should be refused');
        } catch (ValidationException) {
        }

        $post->transitionTo(PostStatus::Publishing);
        $post->transitionTo(PostStatus::Published);
        $again = $service->schedule($video, $account, now()->addDay(), allowReuse: true);
        $this->assertSame(1, $again->reuse_sequence);
    }

    public function test_cancel_and_resume_clear_overdue_unattempted_posts(): void
    {
        [$post, , $account] = $this->duePost(Platform::Facebook, ['scheduled_at' => now()->subHours(3)]);
        [$future] = $this->duePost(Platform::Facebook, ['scheduled_at' => now()->addHours(3)]);
        ScheduledPost::whereKey($future->id)->update(['social_account_id' => $account->id]);

        $this->assertSame(1, app(ScheduleService::class)->clearOverdue($account));
        $this->assertNull(ScheduledPost::find($post->id));

        app(ScheduleService::class)->cancel($future->fresh());
        $this->assertSame(PostStatus::Cancelled, $future->fresh()->status);
    }
}
