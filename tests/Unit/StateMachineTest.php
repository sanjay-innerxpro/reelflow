<?php

namespace Tests\Unit;

use App\Enums\Platform;
use App\Enums\PostStatus;
use App\Enums\VideoStatus;
use App\Exceptions\InvalidStateTransition;
use App\Models\ActivityLog;
use App\Models\ScheduledPost;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\BuildsFixtures;
use Tests\TestCase;

class StateMachineTest extends TestCase
{
    use BuildsFixtures;
    use RefreshDatabase;

    public function test_valid_post_transition_is_persisted_and_audited(): void
    {
        $this->fakeInfrastructure();
        [$post] = $this->duePost(Platform::Facebook);

        $post->transitionTo(PostStatus::Publishing);

        $this->assertSame(PostStatus::Publishing, $post->fresh()->status);
        $log = ActivityLog::where('action', 'scheduledpost.status_changed')->first();
        $this->assertSame('scheduled', $log->from_status);
        $this->assertSame('publishing', $log->to_status);
    }

    public function test_invalid_transition_is_rejected(): void
    {
        $this->fakeInfrastructure();
        [$post] = $this->duePost(Platform::Facebook);

        $this->expectException(InvalidStateTransition::class);
        $post->transitionTo(PostStatus::Published);
    }

    public function test_published_is_terminal(): void
    {
        $this->assertSame([], PostStatus::Published->allowedTransitions());
        $this->assertFalse(PostStatus::Published->canTransitionTo(PostStatus::Failed));
        $this->assertFalse(VideoStatus::Discovered->canTransitionTo(VideoStatus::Ready));
    }

    public function test_concurrent_transition_from_stale_copy_fails(): void
    {
        $this->fakeInfrastructure();
        [$post] = $this->duePost(Platform::Facebook);
        $staleCopy = ScheduledPost::find($post->id);

        $post->transitionTo(PostStatus::Publishing);

        $this->expectException(InvalidStateTransition::class);
        $this->expectExceptionMessage('changed concurrently');
        $staleCopy->transitionTo(PostStatus::Cancelled);
    }

    public function test_success_on_one_platform_does_not_touch_other_posts(): void
    {
        $this->fakeInfrastructure();
        [$fb, $video] = $this->duePost(Platform::Facebook);
        $ig = ScheduledPost::factory()->create([
            'video_id' => $video->id,
            'social_account_id' => $this->account(Platform::Instagram)->id,
            'platform' => Platform::Instagram,
        ]);

        $fb->transitionTo(PostStatus::Publishing);
        $fb->transitionTo(PostStatus::Published);

        $this->assertSame(PostStatus::Scheduled, $ig->fresh()->status);
    }

    public function test_updating_a_post_never_changes_its_scheduled_time(): void
    {
        $this->fakeInfrastructure();
        [$post] = $this->duePost(Platform::Facebook, ['scheduled_at' => '2026-01-01 10:00:00']);
        $this->travel(3)->hours();

        $post->checkpoint(['external_upload_id' => 'x']);
        $post->transitionTo(PostStatus::Publishing);

        $this->assertSame('2026-01-01 10:00:00', ScheduledPost::find($post->id)->scheduled_at->format('Y-m-d H:i:s'));
    }
}
